"""Validate existing RDS recovery metadata before the fixed founder schema migration."""
import datetime as dt
def validate(rows, host, now=None):
    now=now or dt.datetime.now(dt.timezone.utc)
    selected=[r for r in rows if r.get('host')==host]
    if len(selected)!=1:raise ValueError('Expected one exact application database.')
    row=selected[0]
    if not str(row.get('arn','')).startswith('arn:aws:rds:af-south-1:466401734428:db:'):raise ValueError('Unexpected database account or region.')
    if row.get('state') not in ('available','backing-up') or row.get('encrypted') is not True or not isinstance(row.get('retention'),int) or row['retention']<1:raise ValueError('An encrypted database with retained automated backups is required.')
    latest=dt.datetime.fromisoformat(str(row.get('latest','')).replace('Z','+00:00'))
    if latest.tzinfo is None or not -300<=(now-latest).total_seconds()<=86400:raise ValueError('A recent recoverable backup window is required.')
    return {'database_arn':row['arn'],'latest_restorable_time':latest.isoformat(),'backup_retention_days':row['retention'],'verified_at':now.isoformat()}
if __name__=='__main__':
    import json,sys
    print(json.dumps(validate(json.load(open(sys.argv[1])),sys.argv[2])))
