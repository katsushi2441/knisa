<?php
// php tests/parity.test.php — 同じ入力で knisa-core.php と knisa-core.js の答えが一致するか
require __DIR__ . '/../knisa-core.php';
$M = 10000; $cases = [];
$cases[] = [['year'=>2024,'frame'=>'tsumitate','type'=>'buy','amount'=>120*$M],['year'=>2024,'frame'=>'growth','type'=>'buy','amount'=>240*$M]];
$full = []; for ($y=2024;$y<=2028;$y++){ $full[]=['year'=>$y,'frame'=>'tsumitate','type'=>'buy','amount'=>120*$M]; $full[]=['year'=>$y,'frame'=>'growth','type'=>'buy','amount'=>240*$M]; }
$full[] = ['year'=>2029,'frame'=>'growth','type'=>'sell','amount'=>300*$M];
$cases[] = $full;
$cases[] = array_merge($full, [['year'=>2029,'frame'=>'growth','type'=>'buy','amount'=>100*$M]]);
$g = []; for ($y=2024;$y<=2029;$y++) $g[]=['year'=>$y,'frame'=>'growth','type'=>'buy','amount'=>240*$M];
$cases[] = $g;
$cases[] = [['year'=>2024,'frame'=>'tsumitate','type'=>'buy','amount'=>50*$M],['year'=>2024,'frame'=>'tsumitate','type'=>'sell','amount'=>60*$M]];
$cases[] = [['year'=>2025,'frame'=>'growth','type'=>'buy','amount'=>300*$M],['year'=>2027,'frame'=>'tsumitate','type'=>'buy','amount'=>80*$M],['year'=>2027,'frame'=>'growth','type'=>'sell','amount'=>120*$M]];
$fills = [30000, 50000, 300000, 400000, 0];
$fees = [[30000,30,0.05,0.001,0.015],[50000,20,0.04,0.002,0.008]];
$php = ['waku'=>array_map('knisa_calc_waku',$cases),'fill'=>array_map('knisa_frame_fill',$fills),
        'fee'=>array_map(fn($a)=>knisa_fee_gap(...$a),$fees)];
$in = json_encode(['cases'=>$cases,'fills'=>$fills,'fees'=>$fees], JSON_UNESCAPED_UNICODE);
$js = "const K=require('".__DIR__."/../knisa-core.js');const i=$in;"
    . "process.stdout.write(JSON.stringify({waku:i.cases.map(K.calcWaku),fill:i.fills.map(K.frameFill),fee:i.fees.map(a=>K.feeGap(...a))}))";
$tmp = tempnam(sys_get_temp_dir(), 'kn'); file_put_contents($tmp, $js);
$jsOut = json_decode(shell_exec('node ' . escapeshellarg($tmp)), true); unlink($tmp);
// 数値は小数の丸め誤差を許す（1円未満）。Infinity は JS が null で返す
$norm = function ($v) use (&$norm) {
    if (is_array($v)) { return array_map($norm, $v); }
    if (is_float($v) || is_int($v)) { return round($v, 0); }
    return $v;
};
$fail = 0;
foreach (['waku','fill','fee'] as $k) {
    $a = json_encode($norm($php[$k]), JSON_UNESCAPED_UNICODE); $b = json_encode($norm($jsOut[$k]), JSON_UNESCAPED_UNICODE);
    $ok = $a === $b; if (!$ok) { $fail++; }
    echo ($ok ? "OK  " : "NG  ") . $k . "（" . count($php[$k]) . "件）" . ($ok ? '' : "\n  php=" . substr($a,0,300) . "\n  js =" . substr($b,0,300)) . "\n";
}
echo $fail ? "\n$fail 件 NG\n" : "\nPHPとJSの答えは全部一致\n"; exit($fail ? 1 : 0);
