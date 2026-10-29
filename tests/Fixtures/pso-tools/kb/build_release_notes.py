"""Test stand-in for pso-tools kb/build_release_notes.py: BAD refuses the file, NOPDF says pdftotext is missing."""
import sys

data = open(sys.argv[1], 'rb').read()
if b'NOPDF' in data:
    raise SystemExit('pdftotext is not installed here (poppler: brew install poppler, apt install poppler-utils).')
if b'BAD' in data:
    raise SystemExit('No fixes found: is this the IFS Public Release Notes PDF?')
open(sys.argv[2], 'w').write('# Release Notes Watchlist\n\n## Appointment Booking (ABE)\n\n| Build | Date | Fix |\n|---|---|---|\n| 6.18.0.5 | 01/10/2026 | a fix |\n')
print('RESULT build=6.18.0.5 date=01/10/2026 fixes=4700 kept=1001 unread=3')
