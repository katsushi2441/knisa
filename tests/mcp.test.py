#!/usr/bin/env python3
"""knisa_mcp.php を stdio で直接叩く（全ツール＋拒否の経路）。 /usr/bin/python3 tests/mcp.test.py"""
import json, os, subprocess, sys
p = subprocess.Popen(['php', os.path.join(os.path.dirname(__file__), '..', 'knisa_mcp.php')],
                     stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
n = [0]
def call(method, params=None, notify=False):
    msg = {'jsonrpc': '2.0', 'method': method, 'params': params or {}}
    if not notify: n[0] += 1; msg['id'] = n[0]
    p.stdin.write(json.dumps(msg, ensure_ascii=False) + '\n'); p.stdin.flush()
    return None if notify else json.loads(p.stdout.readline())
fail = 0
def check(name, cond):
    global fail
    print(('OK  ' if cond else 'NG  ') + name); fail += 0 if cond else 1
r = call('initialize', {'protocolVersion': '2025-06-18', 'capabilities': {}, 'clientInfo': {'name': 't', 'version': '0'}})
check('initialize', r['result']['serverInfo']['name'] == 'knisa' and r['result']['protocolVersion'] == '2025-06-18')
call('notifications/initialized', notify=True)
tools = [t['name'] for t in call('tools/list')['result']['tools']]
check('tools/list 4本', tools == ['nisa_rules', 'nisa_check_waku', 'nisa_frame_fill', 'nisa_fee_gap'])
tc = lambda name, args: call('tools/call', {'name': name, 'arguments': args})['result']
r = tc('nisa_rules', {}); check('rules', not r['isError'] and '18000000' in r['content'][0]['text'])
M = 10000
tx = [{'year': y, 'frame': f, 'type': 'buy', 'amount': a} for y in range(2024, 2029) for f, a in (('tsumitate', 120*M), ('growth', 240*M))]
tx.append({'year': 2029, 'frame': 'growth', 'type': 'sell', 'amount': 300*M})
r = tc('nisa_check_waku', {'transactions': tx}); t = r['content'][0]['text']
check('5年で埋めて売る→翌年300万', not r['isError'] and '2029年' in t and '復活300.0万円' in t and '収まっています' in t)
r = tc('nisa_check_waku', {'transactions': tx + [{'year': 2029, 'frame': 'growth', 'type': 'buy', 'amount': 100*M}]})
check('売った年に買い直すと超過を指摘', '非課税保有限度額' in r['content'][0]['text'])
r = tc('nisa_check_waku', {'transactions': [{'year': 2023, 'frame': 'growth', 'type': 'buy', 'amount': 1}]})
check('2023年は拒否（旧NISAは外枠）', r['isError'] and '外枠' in r['content'][0]['text'])
r = tc('nisa_check_waku', {'transactions': [{'year': 2024, 'frame': 'ippan', 'type': 'buy', 'amount': 1}]})
check('知らない枠は拒否', r['isError'])
r = tc('nisa_check_waku', {}); check('取引なしは拒否', r['isError'])
r = tc('nisa_frame_fill', {'monthly': 400000}); check('月40万は枠超過', '120.0万円超えます' in r['content'][0]['text'])
r = tc('nisa_frame_fill', {'monthly': 30000}); check('月3万は約50年', '50.0年' in r['content'][0]['text'])
r = tc('nisa_fee_gap', {'monthly': 30000, 'years': 30, 'gross_rate_pct': 5, 'fee_a_pct': 0.1, 'fee_b_pct': 1.5})
check('手数料差 約519.6万円', '519.6万円' in r['content'][0]['text'])
r = tc('nisa_fee_gap', {'monthly': 30000}); check('引数不足は拒否', r['isError'])
r = tc('nisa_unknown', {}); check('知らないツールは拒否', r['isError'])
check('ping', call('ping')['result'] == {})
check('未知メソッドは -32601', call('foo/bar')['error']['code'] == -32601)
p.stdin.close(); p.wait()
print('\n全部OK' if not fail else f'\n{fail}件 NG'); sys.exit(1 if fail else 0)
