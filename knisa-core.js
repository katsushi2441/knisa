/**
 * Kurage NISA 枠計算・試算 — 計算の中身（画面とテストで同じものを使う）
 *
 * 枠のルールは金融庁「NISAを知る」「よくある質問」に書かれているとおりに実装する（2026-09-27 確認）。
 *  - 年間投資枠: つみたて投資枠 120万円・成長投資枠 240万円（合計360万円）
 *  - 非課税保有限度額: 1,800万円（うち成長投資枠 1,200万円）。**簿価（取得金額）で管理する**
 *  - 売却すると、**翌年以降**、売却した商品の簿価の分だけ非課税保有限度額が復活する
 *  - 年間投資枠は復活しない（その年に買って売っても、その年の年間枠は戻らない）
 *  - 2023年までのNISAの保有分は外枠（この計算には入れない）
 *
 * 金額はすべて円の整数で扱う。売却は「売った商品の簿価（買ったときの金額）」で入れる。売却代金ではない。
 */
(function (root) {
  const LIMIT = {
    annualTsumitate: 1200000,
    annualGrowth: 2400000,
    lifetime: 18000000,
    lifetimeGrowth: 12000000,
  };

  /**
   * 取引履歴 → 年ごとの枠の使い方。
   * txs: [{year, frame: 'tsumitate'|'growth', type: 'buy'|'sell', amount}]
   */
  function calcWaku(txs) {
    const years = [...new Set(txs.map((t) => t.year))].sort((a, b) => a - b);
    if (!years.length) return { years: [], errors: [] };
    const first = years[0], last = years[years.length - 1];
    let bookT = 0, bookG = 0;
    const out = [];
    for (let y = first; y <= last + 1; y++) {
      const ts = txs.filter((t) => t.year === y);
      const sum = (frame, type) => ts.filter((t) => t.frame === frame && t.type === type)
        .reduce((a, t) => a + t.amount, 0);
      const buyT = sum('tsumitate', 'buy'), buyG = sum('growth', 'buy');
      const sellT = sum('tsumitate', 'sell'), sellG = sum('growth', 'sell');
      const startT = bookT, startG = bookG;
      const errors = [];
      // 年間投資枠（売っても戻らないので、買った額だけで見る）
      if (buyT > LIMIT.annualTsumitate) errors.push(`つみたて投資枠の年間上限（120万円）を ${yen(buyT - LIMIT.annualTsumitate)} 超えています`);
      if (buyG > LIMIT.annualGrowth) errors.push(`成長投資枠の年間上限（240万円）を ${yen(buyG - LIMIT.annualGrowth)} 超えています`);
      // 生涯枠: その年の売却で空いた分は翌年からしか使えないので、年初の簿価＋その年の購入で見る
      const lifeCheck = startT + startG + buyT + buyG;
      if (lifeCheck > LIMIT.lifetime) errors.push(`非課税保有限度額（1,800万円）を ${yen(lifeCheck - LIMIT.lifetime)} 超えています（その年に売った分は翌年まで使えません）`);
      if (startG + buyG > LIMIT.lifetimeGrowth) errors.push(`成長投資枠の保有限度額（1,200万円）を ${yen(startG + buyG - LIMIT.lifetimeGrowth)} 超えています`);
      // 持っていない額は売れない
      if (sellT > startT + buyT) errors.push(`つみたて投資枠で、持っている簿価（${yen(startT + buyT)}）より多く売っています`);
      if (sellG > startG + buyG) errors.push(`成長投資枠で、持っている簿価（${yen(startG + buyG)}）より多く売っています`);
      bookT = Math.max(0, startT + buyT - sellT);
      bookG = Math.max(0, startG + buyG - sellG);
      const endTotal = bookT + bookG;
      if (y <= last) {
        out.push({
          year: y, buyT, buyG, sellT, sellG,
          annualLeftT: Math.max(0, LIMIT.annualTsumitate - buyT),
          annualLeftG: Math.max(0, LIMIT.annualGrowth - buyG),
          bookT, bookG, bookTotal: endTotal,
          // 翌年に使える生涯枠（売却で空いた分はここで効く）
          nextLifetimeRoom: Math.max(0, LIMIT.lifetime - endTotal),
          nextGrowthRoom: Math.max(0, Math.min(LIMIT.lifetimeGrowth - bookG, LIMIT.lifetime - endTotal)),
          restoredNextYear: sellT + sellG,
          errors,
        });
      }
    }
    return { years: out, errors: out.flatMap((r) => r.errors.map((e) => `${r.year}年: ${e}`)) };
  }

  /** 毎月の積立額 → 年間枠と生涯枠がどう埋まるか（売らない前提）。 */
  function frameFill(monthly) {
    const perYear = monthly * 12;
    const toT = Math.min(perYear, LIMIT.annualTsumitate);
    const toG = Math.max(0, Math.min(perYear - toT, LIMIT.annualGrowth));
    const over = Math.max(0, perYear - LIMIT.annualTsumitate - LIMIT.annualGrowth);
    const yearsToFill = perYear > 0 ? LIMIT.lifetime / Math.min(perYear, LIMIT.annualTsumitate + LIMIT.annualGrowth) : Infinity;
    return { perYear, toT, toG, over, yearsToFill };
  }

  // 同じ入力なら同じ結果になるよう、乱数は種つきにする
  function rng(seed) {
    let a = seed >>> 0;
    return function () {
      a = (a + 0x6D2B79F5) >>> 0;
      let t = a;
      t = Math.imul(t ^ (t >>> 15), t | 1);
      t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  function normal(r) {
    let u = 0, v = 0;
    while (u === 0) u = r();
    while (v === 0) v = r();
    return Math.sqrt(-2 * Math.log(u)) * Math.cos(2 * Math.PI * v);
  }
  function pct(sorted, p) {
    const i = (sorted.length - 1) * p, lo = Math.floor(i), hi = Math.ceil(i);
    return sorted[lo] + (sorted[hi] - sorted[lo]) * (i - lo);
  }

  /**
   * 積立の試算。**予想ではなく、置いた仮定から出る幅。**
   *  年率（中央）= 配当利回り + 企業の利益の伸び − 信託報酬
   *  （株価の割高・割安の変化は長い目で見て平均0と置く）
   *  値動きの大きさ vol（年率の標準偏差）で、良かった場合・悪かった場合の幅を出す。
   * 取り崩し（withdrawMonthly>0）は積立の終わりから始め、何年もつかを見る。
   */
  function simulate(p) {
    const months = Math.round(p.years * 12);
    const r = p.divYield + p.growth - p.fee;          // 年率（中央値の伸び）
    const mu = Math.log(1 + r) / 12, sd = p.vol / Math.sqrt(12);
    const paths = p.paths || 2000;
    const rand = rng(p.seed || 20260927);
    // 取り崩しは別の乱数列にする。同じ列を使うと、取り崩し額を入れただけで積立の結果まで変わる
    const rand2 = rng((p.seed || 20260927) + 1);
    const finals = [];
    const byYear = Array.from({ length: p.years + 1 }, () => []);
    const lastYears = [];
    const drawMonths = Math.round((p.drawYears || 40) * 12);
    for (let k = 0; k < paths; k++) {
      let v = 0;
      byYear[0].push(0);
      for (let m = 1; m <= months; m++) {
        v = (v + p.monthly) * Math.exp(mu + sd * normal(rand));
        if (m % 12 === 0) byYear[m / 12].push(v);
      }
      finals.push(v);
      if (p.withdrawMonthly > 0) {
        let w = v, lasted = drawMonths;
        for (let m = 1; m <= drawMonths; m++) {
          w = w * Math.exp(mu + sd * normal(rand2)) - p.withdrawMonthly;
          if (w <= 0) { lasted = m; break; }
        }
        lastYears.push(lasted / 12);
      }
    }
    finals.sort((a, b) => a - b);
    const band = byYear.map((arr, y) => {
      const s = arr.slice().sort((a, b) => a - b);
      return { year: y, p10: pct(s, 0.1), p50: pct(s, 0.5), p90: pct(s, 0.9), paid: p.monthly * 12 * y };
    });
    lastYears.sort((a, b) => a - b);
    return {
      rate: r, paid: p.monthly * months,
      p10: pct(finals, 0.1), p50: pct(finals, 0.5), p90: pct(finals, 0.9),
      lossProb: finals.filter((x) => x < p.monthly * months).length / finals.length,
      band,
      lasts: lastYears.length ? { p10: pct(lastYears, 0.1), p50: pct(lastYears, 0.5), cap: p.drawYears || 40 } : null,
    };
  }

  /** 手数料の差が、同じ積立でいくらの差になるか（値動きなし・中央の伸びで比べる）。 */
  function feeGap(monthly, years, grossRate, feeA, feeB) {
    const fv = (rate) => {
      let v = 0;
      const g = Math.pow(1 + rate, 1 / 12);
      for (let m = 0; m < years * 12; m++) v = (v + monthly) * g;
      return v;
    };
    const a = fv(grossRate - feeA), b = fv(grossRate - feeB);
    return { a, b, gap: a - b };
  }

  function yen(n) { return Math.round(n).toLocaleString('ja-JP') + '円'; }

  const api = { LIMIT, calcWaku, frameFill, simulate, feeGap, yen };
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  else root.KNISA = api;
})(typeof window !== 'undefined' ? window : globalThis);
