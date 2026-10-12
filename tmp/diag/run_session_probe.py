import os, json, subprocess, sys
env = dict(os.environ)
env['INSTAGRAPI_SESSIONID'] = '32643320551%3AGJuTkSUWJw8Jpc%3A25%3AAYk7_Qeyxxi5zi1AAEIE9IO2xqGuikZc3VX8uueLBw'
env['INSTAGRAPI_DS_USER_ID'] = '32643320551'
cmd = ['python3', 'docker/php/instagrapi/instagram_profile.py', '--mode', 'session']
p = subprocess.run(cmd, cwd='/var/www/html', env=env, capture_output=True, text=True, timeout=60)
print('RC:', p.returncode)
try:
    print('STDOUT:', p.stdout[:1500])
except Exception as e:
    print('stdout err', e)
print('STDERR:', p.stderr[:1500])
