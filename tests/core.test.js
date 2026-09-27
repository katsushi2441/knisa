// node tests/core.test.js — 枠のルールを手で計算した答えと突き合わせる
const K = require('../knisa-core.js');
let fail = 0;
const eq = (name, got, want) => { const ok = JSON.stringify(got) === JSON.stringify(want); if (!ok) fail++; console.log((ok ? 'OK  ' : 'NG  ') + name + (ok ? '' : `  got=${JSON.stringify(got)} want=${JSON.stringify(want)}`)); };
const M = 10000;

// 1. 年間枠いっぱい：つみたて120万＋成長240万 → 残り0、生涯の残り1,440万
let r = K.calcWaku([{year:2024,frame:'tsumitate',type:'buy',amount:120*M},{year:2024,frame:'growth',type:'buy',amount:240*M}]);
eq('年間枠いっぱい 残り', [r.years[0].annualLeftT, r.years[0].annualLeftG], [0, 0]);
eq('年間枠いっぱい 翌年の生涯残り', r.years[0].nextLifetimeRoom, 1440*M);
eq('年間枠いっぱい エラーなし', r.errors, []);

// 2. 年間枠は売っても戻らない：成長で240万買って100万（簿価）売っても、その年はもう買えない
r = K.calcWaku([{year:2024,frame:'growth',type:'buy',amount:240*M},{year:2024,frame:'growth',type:'sell',amount:100*M},{year:2024,frame:'growth',type:'buy',amount:10*M}]);
eq('売っても年間枠は戻らない', r.errors.length > 0 && /年間上限/.test(r.errors[0]), true);

// 3. 生涯枠の復活は翌年：5年で1,800万埋めて、6年目に300万売る → 6年目は買えない、7年目に300万分使える
let tx = [];
for (let y = 2024; y <= 2028; y++) { tx.push({year:y,frame:'tsumitate',type:'buy',amount:120*M},{year:y,frame:'growth',type:'buy',amount:240*M}); }
tx.push({year:2029,frame:'growth',type:'sell',amount:300*M});
r = K.calcWaku(tx);
eq('5年で1,800万 翌年の生涯残り', r.years.find(x=>x.year===2028).nextLifetimeRoom, 0);
eq('6年目に売ると 翌年復活300万', r.years.find(x=>x.year===2029).restoredNextYear, 300*M);
eq('7年目に使える生涯枠', r.years.find(x=>x.year===2029).nextLifetimeRoom, 300*M);
// 同じ年に売って買い直すのはだめ
let tx2 = tx.concat([{year:2029,frame:'growth',type:'buy',amount:100*M}]);
eq('売った年に買い直すと生涯枠超過', K.calcWaku(tx2).errors.some(e=>/非課税保有限度額/.test(e)), true);
// 翌年なら買える
let tx3 = tx.concat([{year:2030,frame:'growth',type:'buy',amount:240*M},{year:2030,frame:'tsumitate',type:'buy',amount:60*M}]);
eq('翌年に300万買い直すのはOK', K.calcWaku(tx3).errors, []);

// 4. 成長投資枠の保有限度1,200万：成長だけで5年×240万=1,200万 → 6年目の成長は不可
tx = []; for (let y = 2024; y <= 2029; y++) tx.push({year:y,frame:'growth',type:'buy',amount:240*M});
eq('成長だけで6年目は1,200万超過', K.calcWaku(tx).errors.some(e=>/2029年.*成長投資枠の保有限度額/.test(e)), true);

// 5. 持っていない額は売れない
eq('持っていない額は売れない', K.calcWaku([{year:2024,frame:'tsumitate',type:'buy',amount:50*M},{year:2024,frame:'tsumitate',type:'sell',amount:60*M}]).errors.some(e=>/多く売っています/.test(e)), true);

// 6. 積立額→枠の埋まり方: 月30万=年360万 → 5年で1,800万
let f = K.frameFill(30*M); eq('月30万は5年で埋まる', [f.toT, f.toG, f.over, f.yearsToFill], [120*M, 240*M, 0, 5]);
f = K.frameFill(5*M); eq('月5万はつみたて枠内・30年', [f.toT, f.toG, f.yearsToFill], [60*M, 0, 30]);

// 7. 試算: 値動き0なら中央値=決定的な複利、10%=90%
let s = K.simulate({monthly:3*M, years:20, divYield:0.02, growth:0.03, fee:0.001, vol:0, paths:50});
let det = K.feeGap(3*M, 20, 0.05, 0.001, 0.001).a;
eq('値動き0の中央値=複利計算', Math.round(s.p50), Math.round(det));
eq('値動き0は幅なし', Math.round(s.p10) === Math.round(s.p90), true);
eq('元本', s.paid, 3*M*240);
// 同じ入力なら同じ結果
let s1 = K.simulate({monthly:3*M, years:20, divYield:0.02, growth:0.03, fee:0.001, vol:0.18});
let s2 = K.simulate({monthly:3*M, years:20, divYield:0.02, growth:0.03, fee:0.001, vol:0.18});
eq('種つき乱数で再現する', s1.p50, s2.p50);
let s3 = K.simulate({monthly:3*M, years:20, divYield:0.02, growth:0.03, fee:0.001, vol:0.18, withdrawMonthly:15*M});
eq('取り崩しを入れても積立の結果は同じ', [s3.p10,s3.p50,s3.p90], [s1.p10,s1.p50,s1.p90]);
eq('幅の順序 p10<p50<p90', s1.p10 < s1.p50 && s1.p50 < s1.p90, true);
console.log(`\n中央値の確認: 月3万・20年・年率4.9%・値動き18% → 元本${K.yen(s1.paid)} / 下位10% ${K.yen(s1.p10)} / 中央 ${K.yen(s1.p50)} / 上位10% ${K.yen(s1.p90)} / 元本割れ ${(s1.lossProb*100).toFixed(1)}%`);
const g = K.feeGap(3*M, 30, 0.05, 0.001, 0.015); console.log(`手数料差: 月3万・30年・0.1%と1.5% → 差 ${K.yen(g.gap)}`);
console.log(fail ? `\n${fail}件 NG` : '\n全部OK'); process.exit(fail ? 1 : 0);
