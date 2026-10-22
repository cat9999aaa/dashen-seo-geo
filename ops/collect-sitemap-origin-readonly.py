#!/usr/bin/env python3
"""Read nginx crawler logs over the project's strict SSH profile; emit no visitor IPs."""
import concurrent.futures
import datetime
import json
import hashlib
import ipaddress
import pathlib
import subprocess
import sys
import urllib.request

ROOT = pathlib.Path('/mnt/workspace/01-业务与产品项目/大神网')
REMOTE = r'''
from pathlib import Path
import json,gzip,datetime,collections
now=datetime.datetime.now(datetime.timezone.utc);start=now-datetime.timedelta(hours=24)
files=list(Path('/var/log/nginx').glob('dashen-crawler.log*'));rows=[];errors=0
for p in files:
 with (gzip.open(p,'rt',errors='replace') if p.suffix=='.gz' else p.open(errors='replace')) as f:
  for line in f:
   try:r=json.loads(line);t=datetime.datetime.fromisoformat(r['time'])
   except (ValueError,KeyError):errors+=1;continue
   if r.get('host')!='dashen.wang':continue
   path=r.get('path','');u=r.get('ua','')
   cat='InspectionTool' if 'InspectionTool' in u else 'Googlebot' if 'Googlebot' in u else 'GoogleOther' if 'GoogleOther' in u else 'OtherGoogleUA' if 'Google' in u else 'nonGoogleUA'
   ismap=('sitemap' in path and path.endswith('.xml')) or path=='/robots.txt'
   if cat!='nonGoogleUA' or (ismap and t>=start):
    rows.append({'time':t.isoformat(),'ip':r.get('cf_ip'),'category':cat,'path':path if ismap else '[other route]','status':r['status'],'upstream':r.get('upstream_status'),'bytes':r.get('bytes'),'duration':r.get('duration')})
print(json.dumps({'atUTC':now.isoformat(),'startUTC':start.isoformat(),'files':[{'name':p.name,'bytes':p.stat().st_size} for p in files],'parseErrors':errors,'rows':rows},separators=(',',':')))
'''

def verify(ip):
    # External process gives each DNS call a wall-clock bound; only Google addresses survive output.
    try:
        r = subprocess.run(['getent', 'hosts', ip], capture_output=True, text=True, timeout=4)
        fields = r.stdout.split()
        ptr = fields[1].lower().rstrip('.') if len(fields) > 1 else ''
        if not ptr.endswith('.googlebot.com'):
            return None
        r = subprocess.run(['getent', 'ahosts', ptr], capture_output=True, text=True, timeout=4)
        forward = sorted({line.split()[0] for line in r.stdout.splitlines() if line.split()})
        if ip not in forward:
            return None
        return {'googleIP': ip, 'ptr': ptr, 'forward': forward}
    except (subprocess.TimeoutExpired, OSError):
        return None

def main():
    r = subprocess.run(['ssh', '-F', str(ROOT/'access/ssh_config'), '-o', 'ConnectTimeout=15',
                        'dashen', 'python3 -'], input=REMOTE, capture_output=True, text=True, timeout=60, cwd=ROOT)
    if r.returncode:
        raise RuntimeError('readonly SSH collector failed: exit=' + str(r.returncode))
    data = json.loads(r.stdout)
    rows = data.pop('rows')
    start = data['startUTC']
    ranges_url = 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json'
    with urllib.request.urlopen(ranges_url, timeout=15) as response:
        ranges_bytes = response.read(512 * 1024 + 1)
    if len(ranges_bytes) > 512 * 1024:
        raise RuntimeError('Google range response bound exceeded')
    ranges = json.loads(ranges_bytes)
    networks = [ipaddress.ip_network(next(iter(p.values()))) for p in ranges['prefixes']]
    def common_ip(row):
        try:address = ipaddress.ip_address(row['ip'])
        except ValueError:return False
        return any(address.version == net.version and address in net for net in networks)
    candidates = [r for r in rows if r['category'] != 'nonGoogleUA' and r['time'] >= start]
    candidates += [r for r in rows if r['category']=='Googlebot' and r['path']!='[other route]']
    candidates += [r for r in rows if r['time'] >= start and common_ip(r)]
    ips = list(dict.fromkeys(r['ip'] for r in sorted(candidates,key=lambda x:x['time'],reverse=True)))
    if len(ips) > 64:
        raise RuntimeError('DNS candidate bound exceeded')
    with concurrent.futures.ThreadPoolExecutor(max_workers=8) as pool:
        checked = dict(zip(ips, pool.map(verify, ips)))
    verified = []
    for row in rows:
        proof = checked.get(row['ip'])
        if proof:
            verified.append({**{k:v for k,v in row.items() if k!='ip'}, **proof, 'officialCommonRange': common_ip(row)})
    recent = [r for r in verified if r['time']>=start]
    maps = [r for r in verified if r['path']!='[other route]' and r['path']!='/robots.txt']
    def latest(category):
        selected = [r for r in maps if r['category']==category]
        return max(selected,key=lambda r:r['time']) if selected else None
    from collections import Counter
    data.update({
        'dnsCandidateCount': len(ips), 'dnsVerifiedCount': sum(bool(v) for v in checked.values()),
        'officialRanges': {'url': ranges_url, 'creationTime': ranges['creationTime'], 'sha256': hashlib.sha256(ranges_bytes).hexdigest()},
        'commonRange24hByUACategory': dict(Counter(r['category'] for r in rows if r['time'] >= start and common_ip(r))),
        'verifiedGoogle24hByCategoryStatus': dict(Counter(r['category']+':'+str(r['status']) for r in recent)),
        'verifiedGoogleMap24h': [r for r in recent if r['path'] not in ('[other route]','/robots.txt')],
        'verifiedGoogleRobots24h': [r for r in recent if r['path']=='/robots.txt'],
        'latestAutomaticMap': latest('Googlebot'), 'latestInspectionMap': latest('InspectionTool'),
        'latestVerifiedGoogle20': sorted(verified,key=lambda r:r['time'],reverse=True)[:20],
        'allMapRobots24hStatusCounts': dict(Counter(str(r['status']) for r in rows if r['time']>=start and r['path']!='[other route]')),
        'unverifiedGoogleUA24hCount': sum(r['time']>=start and r['category']!='nonGoogleUA' and not checked.get(r['ip']) for r in rows),
        'limitations': ['Origin arrivals only: no statement about rejected or edge-cached requests.',
                        'User-agent category is separate from forward-confirmed reverse DNS identity.',
                        'No visitor IPs emitted; only verified public Google addresses.']})
    output = pathlib.Path(sys.argv[1]);output.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    print(json.dumps({k:v for k,v in data.items() if k not in ('verifiedGoogleMap24h','verifiedGoogleRobots24h','latestVerifiedGoogle20')},ensure_ascii=False,indent=2))

if __name__=='__main__':main()
