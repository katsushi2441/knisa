<?php
/**
 * knisa の計算の PHP 版（MCP から使う）。中身は knisa-core.js と同じで、
 * tests/parity.test.php が同じ入力で両方の答えが一致することを確かめる。
 * 画面とAIで答えが食い違わないようにするため、ルールを変えるときは両方を直してテストを通す。
 *
 * 金額はすべて円の整数。売却は売った商品の簿価（買ったときの金額）で入れる。
 */

const KNISA_LIMIT = [
    'annualTsumitate' => 1200000,
    'annualGrowth'    => 2400000,
    'lifetime'        => 18000000,
    'lifetimeGrowth'  => 12000000,
];

function knisa_yen($n) { return number_format((int) round($n)) . '円'; }

/** 取引履歴 → 年ごとの枠の使い方。txs: [['year'=>2024,'frame'=>'tsumitate'|'growth','type'=>'buy'|'sell','amount'=>円], ...] */
function knisa_calc_waku(array $txs): array {
    $L = KNISA_LIMIT;
    $years = array_values(array_unique(array_map(fn($t) => (int) $t['year'], $txs)));
    sort($years);
    if (!$years) { return ['years' => [], 'errors' => []]; }
    $first = $years[0]; $last = $years[count($years) - 1];
    $bookT = 0; $bookG = 0; $out = [];
    for ($y = $first; $y <= $last + 1; $y++) {
        $sum = function ($frame, $type) use ($txs, $y) {
            $s = 0;
            foreach ($txs as $t) {
                if ((int) $t['year'] === $y && $t['frame'] === $frame && $t['type'] === $type) { $s += (int) $t['amount']; }
            }
            return $s;
        };
        $buyT = $sum('tsumitate', 'buy'); $buyG = $sum('growth', 'buy');
        $sellT = $sum('tsumitate', 'sell'); $sellG = $sum('growth', 'sell');
        $startT = $bookT; $startG = $bookG; $errors = [];
        if ($buyT > $L['annualTsumitate']) { $errors[] = 'つみたて投資枠の年間上限（120万円）を ' . knisa_yen($buyT - $L['annualTsumitate']) . ' 超えています'; }
        if ($buyG > $L['annualGrowth']) { $errors[] = '成長投資枠の年間上限（240万円）を ' . knisa_yen($buyG - $L['annualGrowth']) . ' 超えています'; }
        $lifeCheck = $startT + $startG + $buyT + $buyG;
        if ($lifeCheck > $L['lifetime']) { $errors[] = '非課税保有限度額（1,800万円）を ' . knisa_yen($lifeCheck - $L['lifetime']) . ' 超えています（その年に売った分は翌年まで使えません）'; }
        if ($startG + $buyG > $L['lifetimeGrowth']) { $errors[] = '成長投資枠の保有限度額（1,200万円）を ' . knisa_yen($startG + $buyG - $L['lifetimeGrowth']) . ' 超えています'; }
        if ($sellT > $startT + $buyT) { $errors[] = 'つみたて投資枠で、持っている簿価（' . knisa_yen($startT + $buyT) . '）より多く売っています'; }
        if ($sellG > $startG + $buyG) { $errors[] = '成長投資枠で、持っている簿価（' . knisa_yen($startG + $buyG) . '）より多く売っています'; }
        $bookT = max(0, $startT + $buyT - $sellT);
        $bookG = max(0, $startG + $buyG - $sellG);
        $endTotal = $bookT + $bookG;
        if ($y <= $last) {
            $out[] = [
                'year' => $y, 'buyT' => $buyT, 'buyG' => $buyG, 'sellT' => $sellT, 'sellG' => $sellG,
                'annualLeftT' => max(0, $L['annualTsumitate'] - $buyT),
                'annualLeftG' => max(0, $L['annualGrowth'] - $buyG),
                'bookT' => $bookT, 'bookG' => $bookG, 'bookTotal' => $endTotal,
                'nextLifetimeRoom' => max(0, $L['lifetime'] - $endTotal),
                'nextGrowthRoom' => max(0, min($L['lifetimeGrowth'] - $bookG, $L['lifetime'] - $endTotal)),
                'restoredNextYear' => $sellT + $sellG,
                'errors' => $errors,
            ];
        }
    }
    $all = [];
    foreach ($out as $r) { foreach ($r['errors'] as $e) { $all[] = $r['year'] . '年: ' . $e; } }
    return ['years' => $out, 'errors' => $all];
}

/** 毎月の積立額 → 年間枠と生涯枠がどう埋まるか（売らない前提）。 */
function knisa_frame_fill(float $monthly): array {
    $L = KNISA_LIMIT;
    $perYear = $monthly * 12;
    $toT = min($perYear, $L['annualTsumitate']);
    $toG = max(0, min($perYear - $toT, $L['annualGrowth']));
    $over = max(0, $perYear - $L['annualTsumitate'] - $L['annualGrowth']);
    $years = $perYear > 0 ? $L['lifetime'] / min($perYear, $L['annualTsumitate'] + $L['annualGrowth']) : null;
    return ['perYear' => $perYear, 'toT' => $toT, 'toG' => $toG, 'over' => $over, 'yearsToFill' => $years];
}

/** 手数料の差が、同じ積立でいくらの差になるか（値動きなし・中央の伸びで比べる）。 */
function knisa_fee_gap(float $monthly, int $years, float $grossRate, float $feeA, float $feeB): array {
    $fv = function ($rate) use ($monthly, $years) {
        $v = 0.0; $g = pow(1 + $rate, 1 / 12);
        for ($m = 0; $m < $years * 12; $m++) { $v = ($v + $monthly) * $g; }
        return $v;
    };
    $a = $fv($grossRate - $feeA); $b = $fv($grossRate - $feeB);
    return ['a' => $a, 'b' => $b, 'gap' => $a - $b];
}

/** 制度のルール（出典つき）。AIが自分の記憶で答えないよう、ここを正とする。 */
function knisa_rules(): array {
    return [
        'annual' => ['tsumitate' => 1200000, 'growth' => 2400000, 'total' => 3600000,
                     'note' => '年間投資枠は買った額（簿価）で使い切り。売ってもその年の年間投資枠は戻らない'],
        'lifetime' => ['total' => 18000000, 'growth' => 12000000,
                       'note' => '非課税保有限度額は簿価（取得金額）で管理する。売った商品の簿価の分が翌年以降に復活する'],
        'old_nisa' => '2023年までのNISAで持っている分は外枠。売っても新しいNISAの枠は復活しない',
        'eligibility' => '日本国内に住む18歳以上。口座は1人1口座',
        'source' => '金融庁「NISAを知る」「NISA特設ウェブサイト よくある質問」（2026-09-27 確認） https://www.fsa.go.jp/policy/nisa2/',
    ];
}
