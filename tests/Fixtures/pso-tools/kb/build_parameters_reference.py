"""Test stand-in for pso-tools kb/build_parameters_reference.py: BAD refuses the page."""
import sys

data = open(sys.argv[1], 'rb').read()
if b'BAD' in data:
    raise SystemExit("unexpected row: <td>nope</td>")
open(sys.argv[2], 'w').write('application,parameter_id,access,data_type,group,class,default_value,description\nDSE,Mode,,,,,1,The mode\n')
print('RESULT rows=1')
