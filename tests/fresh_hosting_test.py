#!/usr/bin/env python3
import importlib.util,json,pathlib,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('fresh_dns',ROOT/'deploy/aws/fresh_dns.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
class FreshHostingTests(unittest.TestCase):
 def test_workspace_seed_primary(self):
  rec,info=m.ensure_workspace([],m.PRIMARY)
  types={(x['Name'],x['Type']) for x in rec}
  self.assertIn((m.fqdn(m.PRIMARY),'MX'),types);self.assertIn((m.fqdn(m.PRIMARY),'TXT'),types);self.assertIn((m.fqdn('_dmarc.'+m.PRIMARY),'TXT'),types)
  self.assertTrue(info['mx_seeded']);self.assertTrue(info['spf_seeded']);self.assertTrue(info['dmarc_seeded']);self.assertFalse(info['dkim_present'])
 def test_existing_spf_mx_dmarc_preserved(self):
  rec=[m.rr(m.PRIMARY,'MX',['1 smtp.google.com.']),m.rr(m.PRIMARY,'TXT',['"v=spf1 include:_spf.google.com ~all"']),m.rr('_dmarc.'+m.PRIMARY,'TXT',['"v=DMARC1; p=none"'])]
  out,info=m.ensure_workspace(rec.copy(),m.PRIMARY)
  self.assertEqual(len(out),3);self.assertFalse(info['mx_seeded']);self.assertFalse(info['spf_seeded']);self.assertFalse(info['dmarc_seeded'])
 def test_www_cname_wins_over_address_snapshot(self):
  snap={}
  for t,v in [('A',['1.2.3.4']),('AAAA',['2001:db8::1']),('CNAME',['legacy.example.com.'])]:snap[f'www.{m.PRIMARY}|{t}']={'name':'www.'+m.PRIMARY,'type':t,'ttl':300,'values':v}
  out=m.source_records(snap,m.PRIMARY); kinds=[r['Type'] for r in out if r['Name']==m.fqdn('www.'+m.PRIMARY)]
  self.assertEqual(kinds,['CNAME'])
 def test_scripts_are_account_pinned(self):
  for name in ['fresh_application.sh','fresh_edge.sh','google_workspace.sh']:
   text=(ROOT/'deploy/aws'/name).read_text();self.assertIn('466401734428',text);self.assertIn('get-caller-identity',text)
 def test_application_bootstrap_is_packaged(self):
  text=(ROOT/'bin/build-release.py').read_text();self.assertIn('bin/fresh-bootstrap.php',text);self.assertIn('25_fresh_bootstrap.sh',text)
if __name__=='__main__':unittest.main()
