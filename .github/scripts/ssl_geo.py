import json,sys,os,subprocess,time
U,T,D=os.environ['U'],os.environ['T'],os.environ['D']
H=os.environ['H'].replace('https://','').split('/')[0]
if ':' not in H: H+=':2083'
def api(p):
    r=subprocess.run(['curl','-sS','--max-time','60','-H','Authorization: cpanel %s:%s'%(U,T),'https://%s/execute/%s'%(H,p)],capture_output=True,text=True)
    try: return json.loads(r.stdout)
    except Exception: return {'errors':[r.stdout[:300]+r.stderr[:200]]}
def hosts():
    d=api('SSL/installed_hosts')
    for h in d.get('data') or []:
        c=h.get('certificate',{})
        if 'geo' in str(h.get('servername'))+str(c.get('domains')):
            print(h.get('servername'),'| cert:',c.get('subject.commonName'),'| emissor:',c.get('issuer.organizationName'),'| ate',c.get('not_after'))
    print('erros:',d.get('errors'))
print('== dados do dominio'); d=api('DomainInfo/single_domain_data?domain='+D); print(json.dumps(d.get('data') or d.get('errors'),ensure_ascii=False)[:800])
print('== antes'); hosts()
print('== disparar AutoSSL'); print(str(api('SSL/start_autossl_check'))[:300])
for i in range(14):
    time.sleep(15)
    s=api('SSL/is_autossl_check_in_progress').get('data')
    if s in (0,'0',False): break
print('== depois'); hosts()
print('== problemas AutoSSL')
d=api('SSL/get_autossl_problems')
for p in d.get('data') or []:
    if 'geo' in json.dumps(p): print(json.dumps(p,ensure_ascii=False)[:900])
print('erros:',d.get('errors'))
