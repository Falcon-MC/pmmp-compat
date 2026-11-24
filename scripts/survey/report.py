import collections
import json
from pathlib import Path
import sys

output = Path(sys.argv[1])
a = json.loads((output / 'analysis.json').read_text(encoding='utf-8'))
top = {row['project']: row for row in json.loads((output / 'ranking.json').read_text(encoding='utf-8'))}
pc = {k: set(v) for k, v in a['plugin_classes'].items() if v}
n = len(pc)
# plugins in order of how few classes they need, each class weighted by how many plugins use it
usage = collections.Counter(c for v in pc.values() for c in v)
order = sorted(usage, key=lambda name: (-usage[name], name))
rank = {c: i for i, c in enumerate(order)}
need = {p: max(rank[c] for c in v) + 1 for p, v in pc.items()}
curve = []
for k in [n for n in (25, 50, 75, 100, 150, 200, 300, 400, 500) if n < len(order)] + [len(order)]:
    curve.append((k, sum(1 for v in need.values() if v <= k)))
nonet = {p for p in pc if not any(c.startswith('pocketmine\\network') for c in pc[p])}
cheap = sorted(pc, key=lambda p: (need[p], -top.get(p, {}).get('downloads', 0)))

L = []
w = L.append
w('# PocketMine-MP API usage of the most downloaded Poggit plugins\n')
w(f'Source: the Poggit release archive (github.com/poggit/release-archive), {len(top)} most downloaded projects with an API 5 release, '
  f'latest API 5 version each; {n} with detected PocketMine-MP class references. Bundled virions are included.\n')
w('Static reading of the PHP, no code run: method calls are attributed only where the receiver\'s type is visible '
  '(a typed parameter, `instanceof`, or a known chain such as `$this->getServer()`), so method counts are a floor.\n')
w('## How much of the API reaches how many plugins\n')
w('Classes ranked by the number of plugins that use them; a plugin counts once every `pocketmine\\` class it names is covered.\n')
w('| Classes covered | Plugins fully covered |\n|---|---|')
for k, c in curve:
    w(f'| {k} | {c} of {n} ({100*c//n}%) |')
w('')
w(f'{len(nonet)} of {n} plugins name no `pocketmine\\network\\` class.\n')
w('## Features that need more than the API\n')
w('| Feature | Plugins |\n|---|---|')
for cnt, k, _ in a['features']:
    w(f'| {k} | {cnt} |')
w('')
w('## Classes\n')
w('| Plugins | Class |\n|---|---|')
for cnt, k in a['classes'][:150]:
    w(f'| {cnt} | `{k}` |')
w('')
w('## Events listened to\n')
w('| Plugins | Event |\n|---|---|')
for cnt, k in a['events'][:60]:
    w(f'| {cnt} | `{k}` |')
w('')
w('## Classes extended or implemented\n')
w('| Plugins | Class |\n|---|---|')
for cnt, k in a['extends'][:40]:
    w(f'| {cnt} | `{k}` |')
w('')
w('## Methods (where the type is known)\n')
w('| Plugins | Method |\n|---|---|')
for cnt, k in a['methods'][:150]:
    w(f'| {cnt} | `{k}` |')
w('')
w('## Static calls and constants\n')
w('| Plugins | Call |\n|---|---|')
for cnt, k in a['statics'][:80]:
    w(f'| {cnt} | `{k}` |')
w('')
w('## Plugins reachable first\n')
w('Plugins whose classes all sit highest in the ranking above.\n')
w('| Classes needed | Plugin | Downloads | Network classes |\n|---|---|---|---|')
for p in cheap[:60]:
    w(f"| {need[p]} | {p} | {top.get(p, {}).get('downloads', '?')} | {'yes' if p not in nonet else 'no'} |")
w('')
w('## The 30 most downloaded\n')
w('| Downloads | Plugin | Classes needed | Network classes |\n|---|---|---|---|')
for p in sorted(pc, key=lambda p: -top.get(p, {}).get('downloads', 0))[:30]:
    w(f"| {top[p]['downloads']} | {p} | {need[p]} | {'yes' if p not in nonet else 'no'} |")

# which classes come from PocketMine's standalone PHP libraries, reusable as they are
LIBS = [('pocketmine\\math\\', 'pmmp/Math'), ('pocketmine\\nbt\\', 'pmmp/NBT'), ('pocketmine\\color\\', 'pmmp/Color'),
        ('pocketmine\\network\\mcpe\\protocol\\', 'pmmp/BedrockProtocol'), ('pocketmine\\utils\\Binary', 'pmmp/BinaryUtils'),
        ('pocketmine\\utils\\BinaryStream', 'pmmp/BinaryUtils'), ('pocketmine\\errorhandler\\', 'pmmp/ErrorHandler'),
        ('pocketmine\\snooze\\', 'pmmp/Snooze'), ('pocketmine\\thread\\', 'server (threads)'), ('pocketmine\\data\\bedrock\\', 'server data')]
def lib_of(c):
    for prefix, lib in LIBS:
        if c.startswith(prefix):
            return lib
    return 'server API'
by_lib = collections.Counter(lib_of(c) for c in usage)
by_lib_use = collections.Counter()
for c, u in usage.items(): by_lib_use[lib_of(c)] += u
L2 = ['## Where the classes come from\n', 'Standalone PocketMine libraries are plain PHP under LGPL and can be bundled as they are; the rest is the server API to implement.\n',
      '| Source | Classes | Uses |', '|---|---|---|']
for lib, cnt in by_lib.most_common():
    L2.append(f'| {lib} | {cnt} | {by_lib_use[lib]} |')
# coverage once the standalone libraries come for free
server = {p: {c for c in v if lib_of(c) == 'server API'} for p, v in pc.items()}
su = collections.Counter(c for v in server.values() for c in v)
sorder = {c: i for i, c in enumerate(sorted(su, key=lambda name: (-su[name], name)))}
sneed = {p: (max(sorder[c] for c in v) + 1 if v else 0) for p, v in server.items()}
L2 += ['', 'With those libraries bundled, server API classes to write against plugins fully covered:\n', '| Server classes | Plugins |', '|---|---|']
for k in (25, 50, 100, 150, 200, 300, len(su)):
    c = sum(1 for v in sneed.values() if v <= k)
    L2.append(f'| {k} | {c} of {n} ({100*c//n}%) |')
text = '\n'.join(L) + '\n'
marker = '## Features that need more than the API'
(output / 'REPORT.md').write_text(text.replace(marker, '\n'.join(L2) + '\n\n' + marker, 1), encoding='utf-8')
