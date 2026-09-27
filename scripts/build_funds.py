#!/usr/bin/env python3
"""金融庁「つみたて投資枠対象商品届出一覧（対象資産別）」の Excel → knisa-funds.json。

  python3 scripts/build_funds.py data/fsa_tsumitate_by_asset_20260918.xlsx 2026-09-18

出典: 金融庁ウェブサイト https://www.fsa.go.jp/policy/nisa2/products/index.html
公共データ利用規約（第1.0版）により、出典と「加工して作成した」ことを画面に書いて使う。

- 表の左の区分（単一指数・国内型・指数名）は結合セルで、先頭の行にしか値が無い。下へ引き継ぐ
- 見出しに書かれた本数（例「287本」）と読み取った行数が合わなければ止まる
- **手数料（信託報酬）は、この一覧に無い。** 載せない
- 指数が「どの地域の株か」は指数の定義から決めた当社の分類（下の REGION）。構成比は持たない
"""
import json, re, sys
from pathlib import Path
import openpyxl

ROOT = Path(__file__).resolve().parents[1]
src, as_of = sys.argv[1], sys.argv[2]

# 指数 → 何を持つか（地域）。構成比ではなく、指数の定義による分類
REGION = {
    'TOPIX': 'jp', '日経平均株価': 'jp', 'JPX日経インデックス400': 'jp', 'JPXプライム150': 'jp',
    'JPXプライム150指数': 'jp', '読売株価指数': 'jp', 'MSCI Japan Index': 'jp',
    'S&P500': 'us', 'CRSP U.S. Total Market Index': 'us',
    'MSCI World Index （MSCIコクサイ・インデックス）': 'dev', 'FTSE Developed All Cap Index': 'dev',
    'MSCI World Index （MSCIコクサイ・インデックス': 'dev', 'MSCI WORLD IMI Index': 'dev',
    'MSCI Europe Index': 'eu', 'STOXX Europe 600': 'eu',
    'MSCI Emerging Markets Index': 'em', 'FTSE Emerging Index': 'em', 'FTSE RAFI Emerging Index': 'em',
    'MSCI ACWI Index': 'all', 'FTSE Global All Cap Index': 'all',
}
clean = lambda s: re.sub(r'[\s　]+', ' ', str(s)).strip() if s is not None else None
# 同じ指数が、投資信託の表とETFの表で違う書き方になっている（ETFの表は末尾が切れていることもある）。そろえる
ALIAS = {'JPXプライム150指数': 'JPXプライム150',
         'MSCI World Index （MSCIコクサイ・インデックス': 'MSCI World Index （MSCIコクサイ・インデックス）'}
norm_index = lambda s: ALIAS.get(s, s) if s else s


def sheet_rows(ws):
    return [list(r) + [None] * 6 for r in ws.iter_rows(values_only=True)]


def declared(rows):
    for r in rows[:6]:
        m = re.search(r'(\d+)本', str(r[0] or ''))
        if m:
            return int(m.group(1))
    return None


def read(ws, ncat, header_word):
    """左 ncat 列が結合セルの区分、その右がファンド名・運用会社。"""
    rows = sheet_rows(ws)
    hdr = next(i for i, r in enumerate(rows) if r[0] and header_word in str(r[0]))
    cur = [None] * ncat
    out = []
    for r in rows[hdr + 1:]:
        if not any(r[:ncat + 2]):
            continue
        for k in range(ncat):
            if r[k] is not None:
                cur[k] = clean(r[k])
        if r[ncat] is None or str(r[ncat]).startswith('※'):
            continue
        out.append(cur[:] + [clean(r[ncat]), clean(r[ncat + 1])])
    want = declared(rows)
    if want is not None and want != len(out):
        sys.exit(f'{ws.title}: 見出しは{want}本、読めたのは{len(out)}本。表の形が変わっている')
    return out, want


wb = openpyxl.load_workbook(src, read_only=True)
idx, n1 = read(wb.worksheets[0], 3, '単一指数')
act, n2 = read(wb.worksheets[1], 2, '国内型')
etf, n3 = read(wb.worksheets[2], 1, '指定指数')

funds = []
for kind, dom, index, name, co in idx:
    single = norm_index(index) if not re.fullmatch(r'\d+指数', index or '') else None
    funds.append({'t': 'index', 'n': name, 'c': co, 'dom': dom.replace(' ', ''),
                  'k': 'balance' if '複数' in kind else 'single', 'i': single,
                  'ni': None if single else int(index.replace('指数', '')),
                  'r': REGION.get(single) if single else None})
for dom, asset, name, co in act:
    funds.append({'t': 'active', 'n': name, 'c': co, 'dom': dom, 'a': asset})
for index, name, co in etf:
    index = norm_index(index)
    funds.append({'t': 'etf', 'n': name, 'c': co, 'i': index, 'r': REGION.get(index)})

unknown = sorted({f['i'] for f in funds if f.get('i') and f.get('r') is None})
if unknown:
    sys.exit('地域の分類が無い指数: ' + ' / '.join(unknown))
out = {'as_of': as_of, 'counts': {'index': n1, 'active': n2, 'etf': n3},
       'source': 'https://www.fsa.go.jp/policy/nisa2/products/index.html', 'funds': funds}
(ROOT / 'knisa-funds.json').write_text(json.dumps(out, ensure_ascii=False, separators=(',', ':')), encoding='utf-8')
print(f'指定インデックス {n1}本（単一指数 {sum(1 for f in funds if f.get("k")=="single")}）／アクティブ等 {n2}本／ETF {n3}本 → knisa-funds.json')
