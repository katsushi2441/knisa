<?php
/**
 * knisa の MCP サーバー（stdio・PHP 1枚・依存なし）。
 *
 * AIアシスタントが NISA の枠の質問に、自分の記憶ではなく制度どおりの計算で答えられるようにする。
 * よくある間違い（売ったらその年の枠が戻る／売った年のうちに生涯枠を使える）を、計算で正せる。
 * 計算は knisa-core.php（画面の knisa-core.js と同じ答えになることを tests/parity.test.php で確認済み）。
 * 書き込みも外部通信もしない。
 *
 *   claude mcp add knisa -- php /path/to/knisa_mcp.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/knisa-core.php';

const KNISA_MCP_VERSION = '1.0.0';

function knisa_tools(): array {
    $tx = ['type' => 'object', 'required' => ['year', 'frame', 'type', 'amount'], 'properties' => [
        'year' => ['type' => 'integer', 'description' => '西暦（2024以降）'],
        'frame' => ['type' => 'string', 'enum' => ['tsumitate', 'growth'], 'description' => 'tsumitate=つみたて投資枠, growth=成長投資枠'],
        'type' => ['type' => 'string', 'enum' => ['buy', 'sell']],
        'amount' => ['type' => 'integer', 'description' => '円。売却は売った商品の簿価（買ったときの金額）。売却代金ではない'],
    ]];
    return [
        ['name' => 'nisa_rules', 'description' => '新しいNISA（2024年〜）の枠のルールを出典つきで返す。年間投資枠・非課税保有限度額・売却時の復活・旧NISAとの関係。',
         'inputSchema' => ['type' => 'object', 'properties' => new stdClass()]],
        ['name' => 'nisa_check_waku', 'description' => 'NISAの取引履歴から、年ごとの年間投資枠の残り、年末の簿価、翌年に使える生涯の枠、売却で翌年に復活する額を計算し、上限を超える取引を指摘する。売却は簿価で渡すこと。',
         'inputSchema' => ['type' => 'object', 'required' => ['transactions'], 'properties' => ['transactions' => ['type' => 'array', 'items' => $tx]]]],
        ['name' => 'nisa_frame_fill', 'description' => '毎月の積立額（円）で、年間投資枠のどこに入るか、年間枠を超える額、売らない場合に生涯の枠1,800万円が埋まるまでの年数を返す。',
         'inputSchema' => ['type' => 'object', 'required' => ['monthly'], 'properties' => ['monthly' => ['type' => 'number', 'description' => '毎月の積立額（円）']]]],
        ['name' => 'nisa_fee_gap', 'description' => '同じ積立で、信託報酬の違う2つの商品を比べたときの将来の差（値動きなし・同じ伸びで比較）。特定の商品の推奨はしない。',
         'inputSchema' => ['type' => 'object', 'required' => ['monthly', 'years', 'gross_rate_pct', 'fee_a_pct', 'fee_b_pct'], 'properties' => [
             'monthly' => ['type' => 'number'], 'years' => ['type' => 'integer'],
             'gross_rate_pct' => ['type' => 'number', 'description' => '手数料を引く前の年率（%）。例: 配当2%＋利益の伸び3%なら 5'],
             'fee_a_pct' => ['type' => 'number'], 'fee_b_pct' => ['type' => 'number']]]],
    ];
}

function knisa_bad(string $msg): array { return [$msg, true]; }

function knisa_call(string $name, array $a): array {
    $M = fn($n) => number_format($n / 10000, 1) . '万円';
    switch ($name) {
        case 'nisa_rules':
            return [json_encode(knisa_rules(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), false];
        case 'nisa_check_waku':
            $txs = $a['transactions'] ?? null;
            if (!is_array($txs) || !$txs) { return knisa_bad('transactions に取引を1件以上渡してください'); }
            if (count($txs) > 2000) { return knisa_bad('取引は2,000件までです'); }
            foreach ($txs as $i => $t) {
                if (!is_array($t) || !isset($t['year'], $t['frame'], $t['type'], $t['amount'])) { return knisa_bad("transactions[$i] に year・frame・type・amount が要ります"); }
                if (!in_array($t['frame'], ['tsumitate', 'growth'], true)) { return knisa_bad("transactions[$i].frame は tsumitate か growth です"); }
                if (!in_array($t['type'], ['buy', 'sell'], true)) { return knisa_bad("transactions[$i].type は buy か sell です"); }
                if ((int) $t['year'] < 2024 || (int) $t['year'] > 2100) { return knisa_bad("transactions[$i].year は2024年以降です（2023年までのNISAは外枠で、この計算に入れません）"); }
                if (!is_numeric($t['amount']) || $t['amount'] < 0) { return knisa_bad("transactions[$i].amount は0以上の円です"); }
            }
            $r = knisa_calc_waku($txs);
            $lines = [];
            foreach ($r['years'] as $y) {
                $lines[] = sprintf('%d年: つみたて年間の残り%s・成長年間の残り%s／年末の簿価%s（うち成長%s）／翌年に使える生涯の枠%s%s',
                    $y['year'], $M($y['annualLeftT']), $M($y['annualLeftG']), $M($y['bookTotal']), $M($y['bookG']),
                    $M($y['nextLifetimeRoom']), $y['restoredNextYear'] ? '（うち売却で復活' . $M($y['restoredNextYear']) . '）' : '');
            }
            $text = implode("\n", $lines) . "\n\n" . ($r['errors'] ? "上限を超える取引:\n- " . implode("\n- ", $r['errors']) : '枠のルールに収まっています。')
                  . "\n\n（売却は簿価で計算。年間投資枠は売っても戻らず、生涯の枠は翌年に復活します）\n\n" . json_encode($r, JSON_UNESCAPED_UNICODE);
            return [$text, false];
        case 'nisa_frame_fill':
            $m = $a['monthly'] ?? null;
            if (!is_numeric($m) || $m < 0) { return knisa_bad('monthly は0以上の円です'); }
            $f = knisa_frame_fill((float) $m);
            $text = $f['over'] > 0
                ? sprintf('毎月%sは年%sで、年間投資枠（360万円）を%s超えます。超えた分はNISAの外になります。', $M($m), $M($f['perYear']), $M($f['over']))
                : sprintf('毎月%sは年%s（つみたて投資枠に%s%s）。売らなければ、生涯の枠1,800万円は約%.1f年で埋まります。',
                    $M($m), $M($f['perYear']), $M($f['toT']), $f['toG'] ? '、成長投資枠に' . $M($f['toG']) : '', $f['yearsToFill'] ?? 0);
            return [$text . "\n\n" . json_encode($f, JSON_UNESCAPED_UNICODE), false];
        case 'nisa_fee_gap':
            foreach (['monthly', 'years', 'gross_rate_pct', 'fee_a_pct', 'fee_b_pct'] as $k) {
                if (!isset($a[$k]) || !is_numeric($a[$k])) { return knisa_bad("$k は数値で渡してください"); }
            }
            if ($a['years'] < 1 || $a['years'] > 60) { return knisa_bad('years は1〜60です'); }
            $g = knisa_fee_gap((float) $a['monthly'], (int) $a['years'], $a['gross_rate_pct'] / 100, $a['fee_a_pct'] / 100, $a['fee_b_pct'] / 100);
            return [sprintf('毎月%s・%d年・手数料前の年率%.2f%%で、信託報酬%.2f%%だと%s、%.2f%%だと%s。差は約%s（値動きを除いた比較）。',
                $M($a['monthly']), $a['years'], $a['gross_rate_pct'], $a['fee_a_pct'], $M($g['a']), $a['fee_b_pct'], $M($g['b']), $M($g['gap']))
                . "\n\n" . json_encode($g, JSON_UNESCAPED_UNICODE), false];
    }
    return knisa_bad("知らないツールです: $name");
}

while (($line = fgets(STDIN)) !== false) {
    $line = trim($line);
    if ($line === '') { continue; }
    $req = json_decode($line, true);
    if (!is_array($req)) { continue; }
    if (!array_key_exists('id', $req)) { continue; }   // notifications/* には応答しない
    $id = $req['id']; $method = $req['method'] ?? ''; $p = $req['params'] ?? [];
    $res = null; $err = null;
    if ($method === 'initialize') {
        $res = ['protocolVersion' => $p['protocolVersion'] ?? '2025-06-18',
                'capabilities' => ['tools' => new stdClass()],
                'serverInfo' => ['name' => 'knisa', 'version' => KNISA_MCP_VERSION]];
    } elseif ($method === 'ping') {
        $res = new stdClass();
    } elseif ($method === 'tools/list') {
        $res = ['tools' => knisa_tools()];
    } elseif ($method === 'tools/call') {
        [$text, $isErr] = knisa_call((string) ($p['name'] ?? ''), (array) ($p['arguments'] ?? []));
        $res = ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isErr];
    } else {
        $err = ['code' => -32601, 'message' => "Method not found: $method"];
    }
    echo json_encode($err ? ['jsonrpc' => '2.0', 'id' => $id, 'error' => $err] : ['jsonrpc' => '2.0', 'id' => $id, 'result' => $res],
                     JSON_UNESCAPED_UNICODE) . "\n";
    flush();
}
