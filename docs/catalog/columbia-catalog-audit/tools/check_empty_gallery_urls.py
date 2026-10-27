from __future__ import annotations
import csv,json,hashlib,os,tempfile,time,threading,concurrent.futures
from pathlib import Path
import requests
R=Path(__file__).resolve().parents[4];O=R/'docs/catalog/columbia-catalog-audit';E=O/'evidence/manufacturer';P=E/'empty-gallery-image-candidates.csv';PROD=E/'empty-gallery-products.csv';SUMMARY=E/'empty-gallery-audit-summary.json';CP=O/'current-checkpoint/dtb_columbia_catalog_official.csv';OFFICIAL=R/'products/launch/official/dtb_official_catalog.csv';h=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
def read(p):
 with p.open(encoding='utf-8-sig',newline='') as f:r=csv.DictReader(f);return list(r.fieldnames),list(r)
fields,rows=read(P);urls=sorted(set(x['intended_media_url'] for x in rows));before={p:h(p) for p in [P,PROD,SUMMARY,CP,OFFICIAL]};mutex=threading.Lock();next_request=0.0
def check(url):
 global next_request
 with mutex:
  wait=max(0,next_request-time.monotonic())
  if wait:time.sleep(wait)
  next_request=time.monotonic()+0.35
 try:
  response=requests.head(url,timeout=(5,12),allow_redirects=True,headers={'User-Agent':'DTB-Catalog-Media-Audit/1.0'})
  status=response.status_code;ctype=response.headers.get('Content-Type','').split(';',1)[0].strip().lower();final=response.url
  if status==200 and ctype.startswith('image/'):state='reachable_image_response'
  elif status==404:state='not_found_404'
  elif status in (403,429,500,502,503,504):state='inconclusive_http'
  elif status==200:state='unexpected_non_image_response'
  else:state='other_http_response'
  return dict(url=url,http_status=str(status),content_type=ctype,final_url=final,classification=state,note='HEAD response; image content and product identity were not visually verified')
 except requests.RequestException as e:return dict(url=url,http_status='',content_type='',final_url='',classification='network_error',note=type(e).__name__)
with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:results=list(pool.map(check,urls))
by={x['url']:x for x in results}
for r in rows:
 q=by[r['intended_media_url']];r['public_http_status']=q['http_status'] or q['classification'];r['public_content_type']=q['content_type'];r['public_check_note']=q['classification']
for p,v in before.items():
 if h(p)!=v:raise RuntimeError('Input changed during live checks: '+str(p))
with tempfile.NamedTemporaryFile(mode='w',encoding='utf-8-sig',newline='',dir=E,prefix='.url-audit-',suffix='.tmp',delete=False) as f:
 tmp=Path(f.name);w=csv.DictWriter(f,fieldnames=fields,lineterminator='\r\n');w.writeheader();w.writerows(rows);f.flush();os.fsync(f.fileno())
os.replace(tmp,P)
with (E/'empty-gallery-url-checks.csv').open('w',encoding='utf-8-sig',newline='') as f:
 w=csv.DictWriter(f,fieldnames=list(results[0]),lineterminator='\r\n');w.writeheader();w.writerows(results)
summary=json.loads(SUMMARY.read_text(encoding='utf8'));summary['public_checks']={'method':'HEAD','distinct_urls':len(urls),'status_counts':dict(__import__('collections').Counter(x['classification'] for x in results)),'per_product_candidate_200':len({x['source_row'] for x in rows if x['public_check_note']=='reachable_image_response'}),'per_product_existing_dtb_candidate_200':len({x['source_row'] for x in rows if x['public_check_note']=='reachable_image_response' and x['assessment']=='existing_dtb_sku_link_review_visual'})};SUMMARY.write_text(json.dumps(summary,indent=2)+'\n',encoding='utf8')
assert h(CP)==before[CP] and h(OFFICIAL)==before[OFFICIAL];print(json.dumps(summary['public_checks'],indent=2))
