<?php
/**
 * Kurage NISA 枠計算・試算（knisa）— デモ。heteml に PHP 1枚＋計算用 JS 1本で置く。
 *
 * 答えること:
 *   1. 新しいNISAの枠はいくら残っているか。売った分はいつ・いくら復活するか（制度どおりの計算）
 *   2. 積み立てたら、どのくらいの幅で増えるか。その「増える分」はどこから来るのか
 * 答えないこと: どの商品を買うべきか（特定の商品は勧めない）。
 *
 * 計算は knisa-core.js にまとめ、tests/core.test.js で制度のルールと突き合わせてある。
 * 社名・色・計測タグは knisa-config.php に書く（本体に置く会社の情報を持たない）。
 */
date_default_timezone_set('Asia/Tokyo');
$CORE = @file_get_contents(__DIR__ . '/knisa-core.js') ?: '';
// 対象商品の一覧（scripts/build_funds.py が金融庁の Excel から作る）。無ければ 3. の節を出さない
$FUNDS = @file_get_contents(__DIR__ . '/knisa-funds.json') ?: '';
$FMETA = $FUNDS !== '' ? json_decode($FUNDS, true) : null;
function h($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }

/*
 * 置く会社ごとの設定。knisa-config.php があれば読む（knisa-config.sample.php を写して使う）。
 * 無ければ社名もロゴも出さない素の画面になり、**アクセス計測は一切しない**。
 * 当社のデモ（proto.exbridge.jp）だけが、自分の knisa-config.php で計測を有効にしている。
 */
$C = [
    'site_url'      => '',                 // この画面の正式なURL（canonical・OGP）。空なら出さない
    'brand_name'    => '',                 // 左上の名前
    'brand_url'     => '',                 // 左上の名前のリンク先
    'logo_url'      => '',                 // 左上のロゴ（正方形）
    'accent'        => '#1f6f9f',          // 見出しとボタンの色
    'og_image'      => '',                 // OGP画像（1200x630）
    'mascot_url'    => '',                 // 題の横の絵（空なら出さない）
    'contact_url'   => '',                 // 下の相談ボタンのリンク先（空なら案内ごと出さない）
    'contact_label' => '相談する',
    'contact_text'  => '',                 // 相談ボタンの上の一文
    'org_ld'        => null,               // 構造化データの Organization（配列）。無ければ出さない
    'head_extra'    => '',                 // <head> の最後に足すもの（計測タグなど）
    'body_extra'    => '',                 // <body> の最初に足すもの
    'header_links'  => [],                 // 右上のリンク [['文字', 'URL'], ...]
    'footer_extra'  => '',                 // フッターの最後の行（会社名など）
    'pv_url'        => '',                 // 紹介動画（mp4）。空なら出さない
    'pv_poster'     => '',
    'pv_seconds'    => 0,
];
if (is_file(__DIR__ . '/knisa-config.php')) {
    $over = include __DIR__ . '/knisa-config.php';
    if (is_array($over)) { $C = array_merge($C, $over); }
}
if (!preg_match('/^#[0-9a-fA-F]{6}$/', $C['accent'])) { $C['accent'] = '#1f6f9f'; }
$URL = $C['site_url'];
$TITLE = '新NISAシミュレーション｜枠の残りと復活を計算';
$DESC = '新しいNISAの枠があといくら使えるか、売った分が翌年いくら復活するかを、取引を入れて計算します。積立の試算は「配当＋企業の利益の伸び−手数料」から幅で出します。特定の商品は勧めません。'
      . ($C['brand_name'] !== '' ? $C['brand_name'] . '。' : '');

$APP = ['@type' => 'WebApplication', 'name' => 'NISA 枠計算・試算',
   'applicationCategory' => 'FinanceApplication', 'operatingSystem' => 'All', 'inLanguage' => 'ja',
   'isAccessibleForFree' => true, 'offers' => ['@type' => 'Offer', 'price' => '0', 'priceCurrency' => 'JPY'],
   'description' => '新しいNISAの年間投資枠・非課税保有限度額（簿価）の残りと、売却した分が翌年に復活する額を計算する。積立の試算を仮定から幅で出す。'];
if ($URL !== '') { $APP['url'] = $URL; $APP['@id'] = $URL . '#app'; }
if (is_array($C['org_ld'])) { $APP['publisher'] = ['@id' => $C['org_ld']['@id'] ?? '']; }
$LD = ['@context' => 'https://schema.org', '@graph' => array_values(array_filter([
  is_array($C['org_ld']) ? $C['org_ld'] : null,
  $APP,
  $C['pv_url'] !== '' ? ['@type' => 'VideoObject', 'name' => 'NISA 枠計算・試算の紹介', 'inLanguage' => 'ja',
    'description' => '新しいNISAの枠の残りと売った分の復活、積立の幅、対象商品の中身を計算する画面の紹介動画。',
    'contentUrl' => $C['pv_url'], 'thumbnailUrl' => $C['pv_poster'], 'uploadDate' => '2026-09-27T20:00:00+09:00',
    'duration' => 'PT' . (int) $C['pv_seconds'] . 'S'] : null,
  $FMETA ? ['@type' => 'Dataset', 'name' => 'つみたて投資枠の対象商品（連動する指数と地域の分類つき）', 'inLanguage' => 'ja',
            'description' => '金融庁「つみたて投資枠対象商品届出一覧（対象資産別）」を加工し、指数に連動する投資信託' . $FMETA['counts']['index'] . '本・アクティブ運用など' . $FMETA['counts']['active'] . '本・ETF' . $FMETA['counts']['etf'] . '本を、連動する指数と地域で分類したもの。',
            'dateModified' => $FMETA['as_of'], 'isBasedOn' => $FMETA['source']] : null,
  ['@type' => 'FAQPage', 'mainEntity' => [
    ['@type' => 'Question', 'name' => 'NISAで売った分の枠は、いつ復活しますか。',
     'acceptedAnswer' => ['@type' => 'Answer', 'text' => '売った年の翌年以降に、売った商品の簿価（買ったときの金額）の分だけ非課税保有限度額（1,800万円）が復活します。売った年のうちには使えません。']],
    ['@type' => 'Question', 'name' => '売ったら、その年の年間投資枠も戻りますか。',
     'acceptedAnswer' => ['@type' => 'Answer', 'text' => '戻りません。年間投資枠（つみたて投資枠120万円・成長投資枠240万円）は、買った額で使い切りです。復活するのは生涯の枠（非課税保有限度額）だけです。']],
    ['@type' => 'Question', 'name' => '2023年までのNISAで持っている分は、新しい枠に含まれますか。',
     'acceptedAnswer' => ['@type' => 'Answer', 'text' => '含まれません。2023年までのNISAの保有分は外枠で管理され、売っても新しいNISAの枠は復活しません。']],
  ]],
]))];
?><!doctype html>
<html lang="ja"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= h($TITLE) ?></title>
<meta name="description" content="<?= h($DESC) ?>">
<?php if ($URL !== '') { ?><link rel="canonical" href="<?= h($URL) ?>"><meta property="og:url" content="<?= h($URL) ?>"><?php } ?>
<meta name="robots" content="index,follow,max-image-preview:large">
<meta property="og:type" content="website"><meta property="og:locale" content="ja_JP">
<?php if ($C['brand_name'] !== '') { ?><meta property="og:site_name" content="<?= h($C['brand_name']) ?>"><?php } ?>
<meta property="og:title" content="<?= h($TITLE) ?>">
<meta property="og:description" content="<?= h($DESC) ?>">
<?php if ($C['og_image'] !== '') { ?><meta property="og:image" content="<?= h($C['og_image']) ?>">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image"><meta name="twitter:image" content="<?= h($C['og_image']) ?>"><?php } ?>
<script type="application/ld+json"><?= json_encode($LD, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<style>
:root{--ink:#1b2530;--sub:#5f6c78;--line:#dfe5ea;--accent:<?= h($C['accent']) ?>;--accent-soft:#e7f1f8;--good:#1a7a5e;--warn:#b4412f;
 --bg:#f7f9fb;--panel:#fff;--band:#cfe3f1}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);line-height:1.8;
 font-family:"Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,sans-serif}
.wrap{max-width:920px;margin:0 auto;padding-inline:16px}
header.top{background:var(--panel);border-bottom:1px solid var(--line);padding-block:12px}
header.top .wrap{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
header.top .brand{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none}
header.top img{width:34px;height:34px;object-fit:contain;border-radius:6px;flex:none}
header.top b{font-size:15px}
header.top .more{color:var(--accent);text-decoration:none;font-size:13px;margin-left:auto}
.hero{display:flex;gap:18px;align-items:center;margin:26px 0 8px}
.hero img{width:92px;height:auto;flex:none}
h1{font-size:27px;line-height:1.45;margin:0;text-wrap:balance;word-break:auto-phrase}
.lead{color:var(--sub);font-size:14.5px;margin:6px 0 0}
.card{background:var(--panel);border:1px solid var(--line);border-radius:12px;padding:18px;margin:18px 0}
.card h2{font-size:18px;margin:0 0 4px;padding-left:10px;border-left:4px solid var(--accent);text-wrap:balance;word-break:auto-phrase}
.note{font-size:13px;color:var(--sub);margin:0 0 12px}
.rules{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px;margin:10px 0 0}
.rule{background:var(--accent-soft);border-radius:10px;padding:10px 12px;font-size:13.5px}
.rule b{display:block;font-size:17px;font-variant-numeric:tabular-nums}
.tbl{overflow-x:auto}
table{border-collapse:collapse;width:100%;font-size:14px;font-variant-numeric:tabular-nums}
th,td{border-bottom:1px solid var(--line);padding:7px 6px;text-align:right;white-space:nowrap}
th{font-size:12.5px;color:var(--sub);font-weight:600}
th:first-child,td:first-child{text-align:left}
input,select{font:inherit;font-size:15px;padding:7px 8px;border:1.5px solid var(--line);border-radius:8px;background:#fff;min-width:0}
input[type=number]{width:100%;text-align:right;font-variant-numeric:tabular-nums}
button{font:inherit;font-size:14px;font-weight:700;padding:8px 14px;border-radius:8px;border:0;cursor:pointer}
.btn{background:var(--accent);color:#fff}.btn2{background:#eef2f5;color:var(--ink)}
button:focus-visible,input:focus-visible,select:focus-visible{outline:3px solid #9cc6e3;outline-offset:1px}
.txrow td{padding:5px 4px}.txrow input{width:100%}.txrow td:first-child input{min-width:5.4em}.txrow td:nth-child(4) input{min-width:8.5em}.txrow select{min-width:6.5em}#txTable{min-width:560px}
.err{color:var(--warn);font-size:13.5px;margin:10px 0 0;padding-left:1.1em}
.ok{color:var(--good);font-size:13.5px;margin:10px 0 0}
.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.grid2 label{display:block;font-size:13px;color:var(--sub)}
.formula{background:#f3f6f8;border-radius:10px;padding:10px 12px;font-size:14px;margin:12px 0;line-height:1.9}
.formula b{font-variant-numeric:tabular-nums}
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px;margin:14px 0}
.stat{border:1px solid var(--line);border-radius:10px;padding:10px 12px}
.stat span{display:block;font-size:12.5px;color:var(--sub)}
.stat b{font-size:19px;font-variant-numeric:tabular-nums}
.stat.mid{border-color:var(--accent);background:var(--accent-soft)}
svg{display:block;width:100%;height:auto;max-width:100%}
.legend{display:flex;gap:14px;flex-wrap:wrap;font-size:12.5px;color:var(--sub);margin-top:6px}
.legend i{display:inline-block;width:14px;height:10px;border-radius:2px;margin-right:5px;vertical-align:middle}
.fsearch{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 10px}.fsearch input{flex:1 1 240px}
.fres{font-size:13.5px;margin:0 0 12px;padding:0;list-style:none}.fres li{border-bottom:1px solid var(--line);padding:6px 0}
.fres small{color:var(--sub)}
.itbl td:nth-child(2),.itbl th:nth-child(2){text-align:left}
.itbl td:nth-child(3),.itbl th:nth-child(3){text-align:right;white-space:nowrap}
.itbl td{white-space:normal}.itbl details summary{cursor:pointer;color:var(--accent)}
.itbl details ul{margin:6px 0 0;padding-left:1.1em;font-size:13px;color:var(--sub)}
.chk{display:flex;flex-wrap:wrap;gap:6px 14px;margin:8px 0}.chk label{font-size:14px;display:flex;gap:6px;align-items:center}
.ovl{background:var(--accent-soft);border-radius:10px;padding:10px 12px;font-size:14px;margin:6px 0 0}
.ovl li{margin:2px 0}
.pv{display:block;width:100%;max-width:100%;height:auto;aspect-ratio:16/9;border-radius:12px;border:1px solid var(--line);background:#e9eef2;margin:18px 0 0}
.cta{background:#f1f7f4;border:1px solid #cfe5dd;border-radius:12px;padding:18px;margin:18px 0}
.cta a{color:var(--good);font-weight:700}
footer{color:var(--sub);font-size:12.5px;border-top:1px solid var(--line);margin-top:30px;padding-block:22px 40px}
@media(max-width:640px){h1{font-size:21px}.hero img{width:64px}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto}}
</style>
<?= $C['head_extra'] ?>
</head><body>
<?= $C['body_extra'] ?>
<?php if ($C['brand_name'] !== '' || $C['header_links']) { ?>
<header class="top"><div class="wrap">
  <?php if ($C['brand_name'] !== '') { ?><a class="brand" href="<?= h($C['brand_url'] ?: '#') ?>"><?php if ($C['logo_url'] !== '') { ?><img src="<?= h($C['logo_url']) ?>" width="34" height="34" alt="<?= h($C['brand_name']) ?>"><?php } ?><b><?= h($C['brand_name']) ?></b></a><?php } ?>
  <?php foreach ($C['header_links'] as $i => $l) { ?><a class="more" href="<?= h($l[1]) ?>"<?= $i ? ' style="margin-left:14px"' : '' ?>><?= h($l[0]) ?></a><?php } ?>
</div></header>
<?php } ?>

<main class="wrap">
<div class="hero">
  <?php if ($C['mascot_url'] !== '') { ?><img src="<?= h($C['mascot_url']) ?>" width="92" height="92" alt=""><?php } ?>
  <div>
    <h1><a href="<?= h($URL ?: '?') ?>" style="color:inherit;text-decoration:none">新NISAの枠はいくら残っている？ 売った分はいつ戻る？</a></h1>
    <p class="lead">取引を入れると、年間投資枠と生涯の枠（簿価で1,800万円）の残り、売った分が翌年に復活する額を、制度のとおりに計算します。後半では、積み立てがどのくらいの幅で増えるかと、その「増える分」がどこから来るのかを試算します。</p>
  </div>
</div>

<?php if ($C['pv_url'] !== '') { ?>
<video class="pv" controls playsinline preload="none"<?= $C['pv_poster'] !== '' ? ' poster="' . h($C['pv_poster']) . '"' : '' ?> src="<?= h($C['pv_url']) ?>" aria-label="紹介動画"></video>
<?php } ?>
<section class="card" aria-labelledby="rules-h">
  <h2 id="rules-h">先に、枠のルール</h2>
  <div class="rules">
    <div class="rule">つみたて投資枠（年間）<b>120万円</b></div>
    <div class="rule">成長投資枠（年間）<b>240万円</b></div>
    <div class="rule">生涯の枠（簿価で管理）<b>1,800万円</b>うち成長投資枠は1,200万円まで</div>
    <div class="rule">売ったとき<b>翌年に復活</b>売った商品の簿価の分。年間投資枠は戻らない</div>
  </div>
  <p class="note" style="margin-top:10px">2023年までのNISAで持っている分は外枠で、この計算には入れません。出典：金融庁「NISAを知る」「よくある質問」（2026年9月確認）。</p>
</section>

<section class="card" aria-labelledby="waku-h">
  <h2 id="waku-h">1. 枠の残りと、売った分の復活を計算する</h2>
  <p class="note">買った額と、売った商品の<b>簿価（買ったときの金額）</b>を入れます。売って受け取った金額ではありません。例の取引が入っているので、書き換えて使ってください。</p>
  <div class="tbl"><table id="txTable">
    <thead><tr><th>年</th><th>枠</th><th>買う・売る</th><th>金額（円・簿価）</th><th></th></tr></thead>
    <tbody></tbody>
  </table></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
    <button type="button" class="btn2" id="addTx">行を足す</button>
    <button type="button" class="btn2" id="exFull">例：5年で埋めて6年目に売る</button>
    <button type="button" class="btn2" id="exSame">例：売った年に買い直す</button>
  </div>
  <div id="wakuMsg"></div>
  <div class="tbl" style="margin-top:12px"><table id="wakuOut">
    <thead><tr><th>年</th><th>つみたて<br>年間の残り</th><th>成長<br>年間の残り</th><th>年末の簿価</th><th>うち成長</th><th>翌年に使える<br>生涯の枠</th><th>翌年に<br>復活する額</th></tr></thead>
    <tbody></tbody>
  </table></div>
</section>

<section class="card" aria-labelledby="sim-h">
  <h2 id="sim-h">2. 積み立てたら、どのくらいの幅で増えるか</h2>
  <p class="note">増える分は「配当」と「企業の利益の伸び」から来て、そこから手数料（信託報酬）が引かれます。数字は予想ではなく<b>ここに置いた仮定</b>です。書き換えると、結果が変わります。</p>
  <div class="grid2">
    <label>毎月の積立額（円）<input type="number" id="monthly" value="30000" min="0" step="1000"></label>
    <label>積み立てる年数<input type="number" id="years" value="20" min="1" max="50" step="1"></label>
    <label>配当利回り（年・%）<input type="number" id="div" value="2" step="0.1"></label>
    <label>企業の利益の伸び（年・%）<input type="number" id="growth" value="3" step="0.1"></label>
    <label>信託報酬（年・%）<input type="number" id="fee" value="0.1" step="0.01" min="0"></label>
    <label>値動きの大きさ（年・%）<input type="number" id="vol" value="18" step="1" min="0"></label>
    <label>積立後に毎月取り崩す額（円・0なら見ない）<input type="number" id="draw" value="0" min="0" step="10000"></label>
  </div>
  <div class="formula" id="formula"></div>
  <div class="stats" id="stats"></div>
  <div id="chart" role="img" aria-label="積立の資産の推移（下位10%・中央・上位10%）"></div>
  <div class="legend"><span><i style="background:var(--band)"></i>下位10%〜上位10%の幅</span><span><i style="background:var(--accent)"></i>真ん中の場合</span><span><i style="background:#9aa6b1"></i>払い込んだ元本</span></div>
  <p class="note" id="frameNote" style="margin-top:10px"></p>
  <p class="note" id="feeNote"></p>
</section>

<?php if ($FMETA) { $cn = $FMETA['counts']; $asof = date('Y年n月j日', strtotime($FMETA['as_of'])); ?>
<section class="card" aria-labelledby="funds-h" id="funds">
  <h2 id="funds-h">3. つみたて投資枠の対象商品を、中身で見る</h2>
  <p class="note">金融庁の一覧（<?= h($asof) ?>時点）では、つみたて投資枠で買える商品は、指数に連動する投資信託が<b><?= (int) $cn['index'] ?>本</b>、それ以外（アクティブ運用など）が<b><?= (int) $cn['active'] ?>本</b>、ETFが<b><?= (int) $cn['etf'] ?>本</b>です。<b>同じ指数に連動するファンドは、持っている中身がほぼ同じです。</b>違いは主に手数料（信託報酬）と運用会社で、手数料はこの一覧に載っていないため、各ファンドの目論見書で確かめてください。</p>
  <div class="fsearch"><input type="search" id="fq" placeholder="ファンド名で探す（例: 全世界、S&amp;P、TOPIX）" aria-label="ファンド名で探す"></div>
  <ul class="fres" id="fres"></ul>
  <div class="tbl"><table class="itbl" id="itbl"><thead><tr><th>連動する指数</th><th>何を持つか</th><th>本数</th></tr></thead><tbody></tbody></table></div>
  <h3 style="font-size:15px;margin:16px 0 0">持っている（持つ予定の）指数どうしの重なり</h3>
  <p class="note" style="margin:2px 0 0">チェックを入れると、同じ地域の株を二重に持っていないかを示します。地域の分類は指数の定義にもとづくもので、構成比までは見ていません。</p>
  <div class="chk" id="chk"></div>
  <div class="ovl" id="ovl">指数を2つ以上選ぶと、ここに重なりが出ます。</div>
  <p class="note" style="margin-top:10px;overflow-wrap:anywhere">出典：金融庁「つみたて投資枠対象商品届出一覧（対象資産別）」<a href="<?= h($FMETA['source']) ?>" rel="noopener"><?= h($FMETA['source']) ?></a>（<?= h($asof) ?>時点）を加工して作成。地域の分類は当社によるものです。</p>
</section>
<?php } ?>

<section class="card" aria-labelledby="why-h">
  <h2 id="why-h">「利益が生まれる構造」を言葉にすると</h2>
  <p style="font-size:14.5px;margin:0 0 8px">NISAは商品ではなく、税金がかからない<b>口座の枠</b>です。何が増えるかは、枠の中で何を買うかで決まります。株式の投資信託なら、増える分の出どころは2つです。</p>
  <p style="font-size:14.5px;margin:0 0 8px"><b>ひとつは配当</b>、企業が稼いだ利益の一部が株主に払われる分。<b>もうひとつは企業の利益の伸び</b>で、利益が増えれば、その会社の株の値打ちも長い目で見て上がります。株価は短期では大きく上下しますが、上の試算ではその上下を「値動きの大きさ」として幅に表しています。</p>
  <p style="font-size:14.5px;margin:0">反対に、配当も利益もない資産は、値上がりの理由が「後から買う人がいること」だけになります。何に投資するにしても、「この利益はどこから来るのか」を一文で言えるかどうかを確かめてから買うのが、いちばん確実な見分け方です。</p>
</section>

<?php if ($C['contact_url'] !== '') { ?>
<section class="cta">
  <?= $C['contact_text'] ?>
  <p style="font-size:14px;margin:8px 0 0"><a href="<?= h($C['contact_url']) ?>"><?= h($C['contact_label']) ?></a></p>
</section>
<?php } ?>

<p class="note">このページは制度の計算と、置いた仮定による試算です。特定の金融商品の購入を勧めるものではありません。実際の枠の残りは、口座のある金融機関の表示で確認してください。</p>
</main>

<footer><div class="wrap">
  制度の出典：金融庁「NISAを知る」「NISA特設ウェブサイト よくある質問」（2026年9月27日確認）。試算は入力した仮定にもとづく計算で、将来の運用成果を示すものではありません。
  <?= $C['footer_extra'] !== '' ? '<br>' . $C['footer_extra'] : '' ?>
</div></footer>

<script><?= $CORE ?></script>
<?php if ($FMETA) { ?><script>window.KNISA_FUNDS=<?= $FUNDS ?>;</script><?php } ?>
<script>
(function(){
const K = window.KNISA, M = 10000;
const $ = (id) => document.getElementById(id);
const tbody = document.querySelector('#txTable tbody');
const EX_BASIC = [[2024,'tsumitate','buy',120*M],[2024,'growth','buy',100*M],[2025,'tsumitate','buy',120*M],[2025,'growth','sell',50*M],[2026,'tsumitate','buy',120*M],[2026,'growth','buy',240*M]];
const EX_FULL = []; for (let y=2024;y<=2028;y++){EX_FULL.push([y,'tsumitate','buy',120*M],[y,'growth','buy',240*M]);} EX_FULL.push([2029,'growth','sell',300*M],[2030,'growth','buy',240*M],[2030,'tsumitate','buy',60*M]);
const EX_SAME = EX_FULL.slice(0,10).concat([[2029,'growth','sell',300*M],[2029,'growth','buy',100*M]]);
let seq = 0;
function row(t){
  const i = ++seq, tr = document.createElement('tr'); tr.className='txrow';
  tr.innerHTML = `<td><input type="number" id="ty${i}" value="${t[0]}" min="2024" max="2080" aria-label="年"></td>`+
    `<td><select id="tf${i}" aria-label="枠"><option value="tsumitate">つみたて</option><option value="growth">成長</option></select></td>`+
    `<td><select id="tt${i}" aria-label="買う・売る"><option value="buy">買う</option><option value="sell">売る（簿価）</option></select></td>`+
    `<td><input type="number" id="ta${i}" value="${t[3]}" min="0" step="10000" aria-label="金額"></td>`+
    `<td><button type="button" class="btn2" aria-label="この行を消す">×</button></td>`;
  tr.querySelector(`#tf${i}`).value=t[1]; tr.querySelector(`#tt${i}`).value=t[2];
  tr.querySelector('button').onclick=()=>{tr.remove();calcW();};
  tr.querySelectorAll('input,select').forEach(e=>e.addEventListener('input',calcW));
  tbody.appendChild(tr);
}
function load(ex){ tbody.innerHTML=''; ex.forEach(row); calcW(); }
function man(n){ return (n/M).toLocaleString('ja-JP',{maximumFractionDigits:1})+'万円'; }
function calcW(){
  const txs=[...tbody.querySelectorAll('tr')].map(tr=>{const [y,f,t,a]=tr.querySelectorAll('input,select');return {year:+y.value,frame:f.value,type:t.value,amount:Math.max(0,+a.value||0)};}).filter(t=>t.year>=2024&&t.amount>0);
  const r=K.calcWaku(txs), ob=document.querySelector('#wakuOut tbody');
  ob.innerHTML=r.years.map(y=>`<tr><td>${y.year}年</td><td>${man(y.annualLeftT)}</td><td>${man(y.annualLeftG)}</td><td>${man(y.bookTotal)}</td><td>${man(y.bookG)}</td><td><b>${man(y.nextLifetimeRoom)}</b></td><td>${y.restoredNextYear?man(y.restoredNextYear):'—'}</td></tr>`).join('');
  $('wakuMsg').innerHTML = r.errors.length ? '<ul class="err">'+r.errors.map(e=>`<li>${e}</li>`).join('')+'</ul>'
    : (r.years.length ? '<p class="ok">この取引なら、枠のルールに収まっています。</p>' : '');
}
$('addTx').onclick=()=>{const last=tbody.querySelector('tr:last-child input');row([last?+last.value:2026,'tsumitate','buy',10*M]);calcW();};
$('exFull').onclick=()=>load(EX_FULL); $('exSame').onclick=()=>load(EX_SAME);
// URLで例を選べる（?ex=full / ?ex=same）。紹介動画の撮影や、説明で「この状態」を見せるときに使う
const Q = new URLSearchParams(location.search);
load(Q.get('ex')==='full' ? EX_FULL : Q.get('ex')==='same' ? EX_SAME : EX_BASIC);

function drawChart(res){
  const W=720,H=300,L=64,R=12,T=12,B=34, n=res.band.length-1;
  const max=Math.max(...res.band.map(b=>b.p90))*1.05||1;
  const x=i=>L+(W-L-R)*i/n, y=v=>T+(H-T-B)*(1-v/max);
  const area=res.band.map((b,i)=>`${x(i)},${y(b.p90)}`).join(' ')+' '+res.band.slice().reverse().map((b,j)=>`${x(n-j)},${y(b.p10)}`).join(' ');
  const line=k=>res.band.map((b,i)=>`${i?'L':'M'}${x(i).toFixed(1)},${y(b[k]).toFixed(1)}`).join('');
  const step=[1,2,5,10,20,50,100,200,500,1000].map(v=>v*1e6).find(v=>max/v<=5)||1e9;
  let grid=''; for(let v=0;v<=max;v+=step){grid+=`<line x1="${L}" x2="${W-R}" y1="${y(v)}" y2="${y(v)}" stroke="#e6ebef"/><text x="${L-6}" y="${y(v)+4}" text-anchor="end" font-size="11" fill="#5f6c78">${(v/M).toLocaleString('ja-JP')}万</text>`;}
  const ty=Math.max(1,Math.ceil(n/6)); let xt=''; for(let i=0;i<=n;i+=ty){xt+=`<text x="${x(i)}" y="${H-12}" text-anchor="middle" font-size="11" fill="#5f6c78">${i}年</text>`;}
  $('chart').innerHTML=`<svg viewBox="0 0 ${W} ${H}" preserveAspectRatio="xMidYMid meet">${grid}${xt}<polygon points="${area}" fill="#cfe3f1"/><path d="${line('paid')}" fill="none" stroke="#9aa6b1" stroke-width="2" stroke-dasharray="5 4"/><path d="${line('p50')}" fill="none" stroke="#1f6f9f" stroke-width="2.5"/></svg>`;
}
function calcS(){
  const p={monthly:Math.max(0,+$('monthly').value||0),years:Math.min(50,Math.max(1,Math.round(+$('years').value||1))),
    divYield:(+$('div').value||0)/100,growth:(+$('growth').value||0)/100,fee:Math.max(0,+$('fee').value||0)/100,
    vol:Math.max(0,+$('vol').value||0)/100,withdrawMonthly:Math.max(0,+$('draw').value||0),drawYears:40,paths:1500};
  const s=K.simulate(p), r=s.rate*100;
  $('formula').innerHTML=`年率 <b>${r.toFixed(2)}%</b> ＝ 配当 <b>${(p.divYield*100).toFixed(1)}%</b> ＋ 利益の伸び <b>${(p.growth*100).toFixed(1)}%</b> − 信託報酬 <b>${(p.fee*100).toFixed(2)}%</b>（株価の割高・割安の変化は、長い目で見て平均0と置いています）`;
  const st=(k,v,c='')=>`<div class="stat ${c}"><span>${k}</span><b>${v}</b></div>`;
  $('stats').innerHTML=st('払い込んだ元本',man(s.paid))+st('悪かった場合（下位10%）',man(s.p10))+st('真ん中の場合',man(s.p50),'mid')+st('良かった場合（上位10%）',man(s.p90))+st('元本を下回る確率',(s.lossProb*100).toFixed(0)+'%')
    +(s.lasts?st('取り崩しがもつ年数（真ん中）',s.lasts.p50>=s.lasts.cap?`${s.lasts.cap}年以上`:s.lasts.p50.toFixed(1)+'年')+st('取り崩しがもつ年数（下位10%）',s.lasts.p10>=s.lasts.cap?`${s.lasts.cap}年以上`:s.lasts.p10.toFixed(1)+'年'):'');
  drawChart(s);
  const f=K.frameFill(p.monthly);
  $('frameNote').textContent = f.over>0 ? `毎月${man(p.monthly)}は年${man(f.perYear)}で、年間投資枠（360万円）を${man(f.over)}超えます。超えた分はNISAの外になります。`
    : `毎月${man(p.monthly)}は年${man(f.perYear)}です（つみたて投資枠に${man(f.toT)}${f.toG?`、成長投資枠に${man(f.toG)}`:''}）。売らなければ、生涯の枠1,800万円は約${f.yearsToFill.toFixed(1)}年で埋まります。`;
  const g=K.feeGap(p.monthly,p.years,p.divYield+p.growth,Math.min(p.fee,0.001),1.5/100);
  $('feeNote').textContent=`手数料の差の大きさ：同じ条件で信託報酬が年0.1%と年1.5%の商品を比べると、${p.years}年後の差は約${man(g.gap)}になります（値動きを除いて計算）。`;
}
['monthly','years','div','growth','fee','vol','draw'].forEach(id=>$(id).addEventListener('input',calcS));
calcS();

// 3. 対象商品を中身で見る（並びは名前順。成績や人気では並べない＝勧めない）
const F = window.KNISA_FUNDS;
if (F) {
  const RL = {jp:'日本株',us:'米国株',dev:'先進国株',eu:'欧州株',em:'新興国株',all:'全世界株'};
  const RD = {jp:'日本の株',us:'米国の株',dev:'日本以外も含む先進国の株（多くのファンドは日本を除く）',eu:'欧州の株',em:'新興国の株',all:'先進国と新興国の株（ふつう日本株も入る）'};
  const esc = (t)=>String(t).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  const byIdx = {};
  F.funds.forEach(f=>{ if(f.i){ (byIdx[f.i]=byIdx[f.i]||{r:f.r,list:[]}).list.push(f); } });
  const bal = F.funds.filter(f=>f.k==='balance'), act = F.funds.filter(f=>f.t==='active');
  const order = ['jp','us','dev','eu','em','all'];
  const idxs = Object.keys(byIdx).sort((a,b)=>order.indexOf(byIdx[a].r)-order.indexOf(byIdx[b].r)||byIdx[b].list.length-byIdx[a].list.length);
  const li = (f)=>`<li>${esc(f.n)} <small>${esc(f.c)}${f.t==='etf'?'・ETF':''}</small></li>`;
  const byName=(a,b)=>a.n.localeCompare(b.n,'ja');
  $('itbl').querySelector('tbody').innerHTML = idxs.map(k=>`<tr><td><details><summary>${esc(k)}</summary><ul>${byIdx[k].list.slice().sort(byName).map(li).join('')}</ul></details></td><td>${RL[byIdx[k].r]}<br><small style="color:var(--sub)">${RD[byIdx[k].r]}</small></td><td>${byIdx[k].list.length}本</td></tr>`).join('')
    + `<tr><td><details><summary>複数の指数を組み合わせたもの（バランス型）</summary><ul>${bal.slice().sort(byName).map(f=>`<li>${esc(f.n)} <small>${esc(f.c)}・${f.ni}指数</small></li>`).join('')}</ul></details></td><td>株と債券などの組み合わせ<br><small style="color:var(--sub)">中身の内訳はこの一覧に無い</small></td><td>${bal.length}本</td></tr>`
    + `<tr><td><details><summary>指数に連動しないもの（アクティブ運用など）</summary><ul>${act.slice().sort(byName).map(f=>`<li>${esc(f.n)} <small>${esc(f.c)}・${esc(f.dom)}・${esc(f.a)}</small></li>`).join('')}</ul></details></td><td>運用会社が銘柄を選ぶ<br><small style="color:var(--sub)">国内型・海外型と資産の区分だけ載っている</small></td><td>${act.length}本</td></tr>`;
  const kind=(f)=>f.i?`${esc(f.i)}（${RL[f.r]}）`:(f.k==='balance'?`バランス型・${f.ni}指数`:`${esc(f.dom)}・${esc(f.a)}・アクティブ運用など`);
  $('fq').addEventListener('input',()=>{
    const q=$('fq').value.trim().toLowerCase();
    if(q.length<1){ $('fres').innerHTML=''; return; }
    const hit=F.funds.filter(f=>f.n.toLowerCase().includes(q)).sort(byName);
    $('fres').innerHTML = hit.length ? `<li><small>${hit.length}本</small></li>`+hit.slice(0,40).map(f=>`<li>${esc(f.n)}<br><small>${esc(f.c)}／${kind(f)}</small></li>`).join('')+(hit.length>40?'<li><small>…ほか。もう少し絞ってください</small></li>':'')
      : '<li><small>一覧に見つかりません。つみたて投資枠の対象でない商品かもしれません（成長投資枠の対象かどうかは別です）。</small></li>';
  });
  $('chk').innerHTML = idxs.map((k,i)=>`<label><input type="checkbox" value="${esc(k)}" id="ck${i}">${esc(k)}</label>`).join('');
  const inside = {all:['jp','us','dev','eu','em'], dev:['us','eu']};
  $('chk').addEventListener('change',()=>{
    const sel=[...$('chk').querySelectorAll('input:checked')].map(c=>c.value);
    if(sel.length<2){ $('ovl').textContent='指数を2つ以上選ぶと、ここに重なりが出ます。'; return; }
    const msgs=[];
    for(let i=0;i<sel.length;i++) for(let j=i+1;j<sel.length;j++){
      const a=sel[i], b=sel[j], ra=byIdx[a].r, rb=byIdx[b].r;
      if(ra===rb) msgs.push(`「${esc(a)}」と「${esc(b)}」は、どちらも${RL[ra]}です。中身の多くが重なります。`);
      else if((inside[ra]||[]).includes(rb)) msgs.push(`「${esc(a)}」（${RL[ra]}）の中にも${RL[rb]}が入っています。「${esc(b)}」を別に持つと、${RL[rb]}の比重がその分上がります。`);
      else if((inside[rb]||[]).includes(ra)) msgs.push(`「${esc(b)}」（${RL[rb]}）の中にも${RL[ra]}が入っています。「${esc(a)}」を別に持つと、${RL[ra]}の比重がその分上がります。`);
    }
    $('ovl').innerHTML = msgs.length ? '<ul style="margin:0;padding-left:1.1em">'+msgs.map(m=>`<li>${m}</li>`).join('')+'</ul><p style="margin:6px 0 0;font-size:13px;color:var(--sub)">重なっていること自体は悪いことではありません。意図して比重を上げているのか、知らずに二重に持っているのかを確かめるためのものです。</p>'
      : '選んだ指数どうしは、持っている地域が分かれています。';
  });
  // ?fq=全世界 で検索、?ovl=S%26P500|MSCI%20ACWI%20Index で重なりを選んだ状態から始める
  if (Q.get('fq')) { $('fq').value = Q.get('fq'); $('fq').dispatchEvent(new Event('input')); }
  if (Q.get('ovl')) {
    const want = Q.get('ovl').split('|');
    $('chk').querySelectorAll('input').forEach(c=>{ c.checked = want.includes(c.value); });
    $('chk').dispatchEvent(new Event('change'));
  }
}
})();
</script>
<?php /* 再販パートナー募集の枠（中身は kurage_web/partner-bar.js）。当社の公開先でだけ読む */ if (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp'): ?><script src=https://kurage.exbridge.jp/partner-bar.js defer></script><?php endif; ?>
</body></html>
