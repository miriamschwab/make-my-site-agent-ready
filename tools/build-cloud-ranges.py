#!/usr/bin/env python3
"""Rebuild includes/data/cloud-ranges.php from each cloud provider's own published range list.

A release-time chore, like refreshing crawler-ranges.php. Not shipped: the release workflow
archives an explicit path list that does not include tools/, and .gitattributes export-ignores it.

    python3 tools/build-cloud-ranges.py            # fetch, build, write
    python3 tools/build-cloud-ranges.py --dry-run  # fetch and report, write nothing

What it does, in order, and why each step exists:

1. Fetches every source below. Azure's Service Tags file moves to a new dated URL every week, so
   its address is read out of Microsoft's download page rather than hard-coded.
2. Keeps only the part of each list that is customer compute. AWS is EC2 only: the AMAZON service
   covers CloudFront and Amazon's own corporate network, and people browse from those. Google is
   cloud.json only, which excludes Googlebot and Google's own services.
3. Collapses each provider's prefixes into the fewest non-overlapping ranges.
4. Drops IANA special-purpose blocks (documentation, 6to4, Teredo, private space) and says so.
   A provider's feed is not always careful: Vultr's lists 2002::/16, which would make every 6to4
   user a "Vultr" visitor.
5. Refuses to write if two providers claim the same addresses. The lookup is a binary search over
   one sorted list and assumes no overlaps, so an overlap would make one provider invisible.
6. Reports, per provider, how many ranges were added and removed against the file being replaced,
   so a refresh is reviewed rather than trusted. A large removal is worth a second look.

Cloudflare, Akamai and Fastly are deliberately absent: Cloudflare WARP and iCloud Private Relay
egress through them, and both carry real people. See the decisions log.
"""

import csv
import io
import ipaddress
import json
import re
import sys
import urllib.request
from datetime import date
from pathlib import Path

OUT = Path(__file__).resolve().parent.parent / 'includes' / 'data' / 'cloud-ranges.php'

UA = 'make-my-site-agent-ready range refresh'


def fetch(url):
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    with urllib.request.urlopen(req, timeout=60) as resp:
        return resp.read().decode('utf-8')


def nets(values):
    out = []
    for v in values:
        v = v.strip()
        if not v or v.startswith('#'):
            continue
        out.append(ipaddress.ip_network(v, strict=False))
    return out


def aws():
    data = json.loads(fetch('https://ip-ranges.amazonaws.com/ip-ranges.json'))
    found = [p['ip_prefix'] for p in data['prefixes'] if p['service'] == 'EC2']
    found += [p['ipv6_prefix'] for p in data['ipv6_prefixes'] if p['service'] == 'EC2']
    return nets(found), 'createDate ' + data['createDate']


def gcp():
    data = json.loads(fetch('https://www.gstatic.com/ipranges/cloud.json'))
    found = [p.get('ipv4Prefix') or p.get('ipv6Prefix') for p in data['prefixes']]
    return nets(found), 'creationTime ' + data['creationTime'][:10]


def azure():
    page = fetch('https://www.microsoft.com/en-us/download/details.aspx?id=56519')
    match = re.search(r'https://download\.microsoft\.com/download/[^"]*ServiceTags_Public_(\d{8})\.json', page)
    if not match:
        sys.exit('Azure: could not find the Service Tags download link')
    data = json.loads(fetch(match.group(0)))
    for value in data['values']:
        if value['name'] == 'AzureCloud':
            return nets(value['properties']['addressPrefixes']), 'ServiceTags_Public_' + match.group(1)
    sys.exit('Azure: no AzureCloud tag in the Service Tags file')


def oracle():
    data = json.loads(fetch('https://docs.oracle.com/en-us/iaas/tools/public_ip_ranges.json'))
    found = [c['cidr'] for r in data['regions'] for c in r['cidrs']]
    return nets(found), 'last_updated_timestamp ' + data['last_updated_timestamp'][:10]


def geofeed(url):
    """An RFC 8805 geofeed: CSV, prefix in the first column, comments start with #."""
    text = fetch(url)
    stamp = ''
    m = re.search(r'Last modified:\s*(\S+)', text)
    if m:
        stamp = 'last modified ' + m.group(1)
    rows = [row[0] for row in csv.reader(io.StringIO(text)) if row and not row[0].startswith('#')]
    return nets(rows), stamp or 'no date published'


def digitalocean():
    return geofeed('https://digitalocean.com/geo/google.csv')


def linode():
    return geofeed('https://geoip.linode.com/')


def vultr():
    data = json.loads(fetch('https://geofeed.constant.com/?json'))
    return nets([s['ip_prefix'] for s in data['subnets']]), 'updated ' + data['updated'][:10]


# IANA special-purpose blocks, which no provider's customer traffic can legitimately come from.
# Vultr's geofeed lists four of them as its own (2026-09-23): 2001:2::/48, 2001:10::/28,
# 2001:db8::/32 and 2002::/16. The last is the one that matters: 6to4 addresses embed the user's
# own IPv4, so keeping it would label real people on 6to4 as a Vultr cloud network. Anything that
# ipaddress does not consider global (private, loopback, link-local, reserved) is dropped too.
SPECIAL = [ipaddress.ip_network(n) for n in (
    '2001::/23',        # IETF protocol assignments: Teredo, benchmarking, ORCHID and the rest
    '2001:db8::/32',    # documentation
    '2002::/16',        # 6to4
    '192.88.99.0/24',   # 6to4 relay anycast (deprecated)
)]


def drop_special(found, label):
    kept = []
    for n in found:
        special = any(n.version == sp.version and (n.subnet_of(sp) or sp.subnet_of(n)) for sp in SPECIAL)
        if special or not n.is_global:
            print(f'  dropped from {label}: {n} (special-purpose, not customer address space)')
            continue
        kept.append(n)
    return kept


# Key, label, source URL(s) as written into the PHP file, fetcher. Order is the order in the file.
PROVIDERS = [
    ('aws', 'AWS', 'https://ip-ranges.amazonaws.com/ip-ranges.json (service EC2 only)', aws),
    ('gcp', 'Google Cloud', 'https://www.gstatic.com/ipranges/cloud.json', gcp),
    ('azure', 'Azure', 'https://www.microsoft.com/en-us/download/details.aspx?id=56519 (AzureCloud tag)', azure),
    ('oracle', 'Oracle Cloud', 'https://docs.oracle.com/en-us/iaas/tools/public_ip_ranges.json', oracle),
    ('digitalocean', 'DigitalOcean', 'https://digitalocean.com/geo/google.csv', digitalocean),
    ('linode', 'Linode (Akamai Cloud)', 'https://geoip.linode.com/', linode),
    ('vultr', 'Vultr', 'https://geofeed.constant.com/?json', vultr),
]


def previous_ranges():
    """The ranges in the current file, per provider, for the added/removed report."""
    import base64
    import struct
    if not OUT.exists():
        return {}
    text = OUT.read_text()
    keys = re.findall(r"^\t\t'([a-z]+)'\s+=> array\($", text, re.M)
    prev = {}
    for tag, fmt, size, shift in (('V4', '>IIB', 9, 0), ('V6', '>QQB', 17, 64)):
        m = re.search(r"<<<'" + tag + r"'\n(.*?)\n" + tag + r"\n", text, re.S)
        if not m:
            continue
        blob = base64.b64decode(m.group(1).replace('\n', ''))
        for i in range(0, len(blob), size):
            s, e, k = struct.unpack(fmt, blob[i:i + size])
            prev.setdefault(keys[k], set()).add((s << shift, e << shift))
    return prev


def main():
    dry = '--dry-run' in sys.argv
    today = date.today().isoformat()
    v4, v6, meta = [], [], []

    for key, label, source, fetcher in PROVIDERS:
        found, vintage = fetcher()
        found = drop_special(found, label)
        c4 = list(ipaddress.collapse_addresses(n for n in found if n.version == 4))
        c6 = list(ipaddress.collapse_addresses(n for n in found if n.version == 6))
        v4 += [(int(n.network_address), int(n.broadcast_address), key) for n in c4]
        v6 += [(int(n.network_address), int(n.broadcast_address), key) for n in c6]
        meta.append((key, label, source, vintage, len(c4), len(c6)))
        print(f'{label:24} {len(c4):5} IPv4  {len(c6):5} IPv6   {vintage}')

    for family, rows in (('IPv4', v4), ('IPv6', v6)):
        rows.sort()
        for a, b in zip(rows, rows[1:]):
            if b[0] <= a[1]:
                sys.exit(f'{family} overlap between {a[2]} and {b[2]}: refusing to write')

    # Every IPv6 range here is a /64 or wider (checked below), so its bounds are fully described by
    # their upper 64 bits: the start's lower half is all zeros and the end's all ones. Storing 16 hex
    # characters instead of 32 halves the IPv6 half of the file and loses nothing.
    for s, e, k in v6:
        if (s & ((1 << 64) - 1)) != 0 or (e & ((1 << 64) - 1)) != (1 << 64) - 1:
            sys.exit(f'IPv6 range narrower than /64 from {k}: the 64-bit encoding cannot hold it')

    new = {}
    for s, e, k in v4 + [(s6 >> 64 << 64, e6 >> 64 << 64, k6) for s6, e6, k6 in v6]:
        new.setdefault(k, set()).add((s, e))
    prev = previous_ranges()
    if prev:
        for key, *_ in PROVIDERS:
            added = len(new.get(key, set()) - prev.get(key, set()))
            removed = len(prev.get(key, set()) - new.get(key, set()))
            print(f'  {key:13} +{added} -{removed}')

    if dry:
        return

    # Packed binary, base64 in a nowdoc: one token per family instead of ~70,000. The array form
    # this replaced was 365 KB and ran PHP_CodeSniffer out of memory in the audit; this is a
    # fraction of the size and costs nothing to tokenise. Record layout, big-endian:
    #   IPv4: start (4 bytes), end (4 bytes), provider index (1 byte)  = 9 bytes
    #   IPv6: start upper 64 bits (8), end upper 64 bits (8), index (1) = 17 bytes
    # The provider index is the position in the providers array, in file order.
    import base64
    import struct
    import textwrap
    index = {m[0]: i for i, m in enumerate(meta)}
    b4 = b''.join(struct.pack('>IIB', s, e, index[k]) for s, e, k in v4)
    b6 = b''.join(struct.pack('>QQB', s >> 64, e >> 64, index[k]) for s, e, k in v6)

    def nowdoc(tag, blob):
        body = '\n'.join(textwrap.wrap(base64.b64encode(blob).decode('ascii'), 100))
        return [f"\t'{tag}'        => <<<'{tag.upper()}'", body, f"{tag.upper()}", '\t,']

    lines = [
        '<?php',
        '/**',
        ' * Published cloud-provider address ranges, for the "cloud network" signal on browser-shaped rows.',
        ' *',
        ' * GENERATED by tools/build-cloud-ranges.py — do not edit by hand; re-run the script. Bundled',
        ' * rather than fetched for the same reason crawler-ranges.php is: fetching would make this plugin',
        ' * call third-party services on a schedule.',
        ' *',
        ' * Each provider\'s prefixes are collapsed into non-overlapping ranges and merged into one sorted',
        ' * list per address family, so a lookup is a binary search. Stored packed and base64-encoded,',
        ' * one fixed-width big-endian record per range:',
        ' *',
        ' * - v4: start (4 bytes), end (4 bytes), provider index (1 byte).',
        ' * - v6: start and end as the upper 64 bits (8 bytes each), provider index (1 byte). Every IPv6',
        ' *   range here is a /64 or wider (the generator refuses anything narrower), so the lower 64',
        ' *   bits carry nothing.',
        ' *',
        ' * The provider index is the position in `providers`, in order. Packed rather than written out',
        ' * as PHP arrays because the array form ran PHP_CodeSniffer out of memory at 128 MB.',
        ' *',
        ' * Staleness here fails the harmless way: a range a provider added after capture reads as "not a',
        ' * cloud network", which under-flags. Nothing is accused by it. See the decisions log.',
        ' *',
        ' * Sources, with the upstream file\'s own vintage at capture:',
    ]
    for key, label, source, vintage, n4, n6 in meta:
        lines.append(f' * - {label}: {source} — {vintage}; {n4} IPv4 / {n6} IPv6 ranges.')
    lines += [
        ' *',
        ' * @package Make_My_Site_Agent_Ready',
        ' */',
        '',
        "if ( ! defined( 'ABSPATH' ) ) {",
        '\texit;',
        '}',
        '',
        'return array(',
        "\t'providers' => array(",
    ]
    width = max(len(m[0]) for m in meta) + 2
    for key, label, source, vintage, n4, n6 in meta:
        lines.append("\t\t" + f"'{key}'".ljust(width) + " => array(")
        lines.append(f"\t\t\t'label'    => '{label}',")
        lines.append(f"\t\t\t'captured' => '{today}',")
        lines.append('\t\t),')
    lines.append('\t),')
    lines += nowdoc('v4', b4)
    lines += nowdoc('v6', b6)
    lines.append(');')
    OUT.write_text('\n'.join(lines) + '\n')
    print(f'wrote {OUT} ({OUT.stat().st_size:,} bytes, {len(v4)} IPv4 + {len(v6)} IPv6 ranges)')


if __name__ == '__main__':
    main()
