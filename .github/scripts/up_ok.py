import json,sys
try:
    j=json.load(sys.stdin)
    print('ok' if j.get('status')==1 and not (j.get('data') or {}).get('failed') else 'erro '+str(j.get('errors'))+str(j.get('messages'))+str((j.get('data') or {}).get('failed')))
except Exception:
    print('erro leitura')
