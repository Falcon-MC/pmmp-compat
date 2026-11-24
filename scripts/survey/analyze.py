import collections
import json
import os
from pathlib import Path
import re
import sys

output = Path(sys.argv[1])
root = output / 'src'
rows = json.loads((output / 'ranking.json').read_text(encoding='utf-8'))
plugins = [row for row in rows if row['extracted']]

USE = re.compile(r'^\s*use\s+(function\s+|const\s+)?([^;]+);', re.M)
FQ = re.compile(r'\\?(pocketmine\\[A-Za-z0-9_\\]+)')
PARAM = re.compile(r'([\\A-Za-z_][\\A-Za-z0-9_]*)\s+&?\$([A-Za-z_]\w*)')
CALL = re.compile(r'\$([A-Za-z_]\w*)\s*->\s*([A-Za-z_]\w*)\s*\(')
STATIC = re.compile(r'\b([A-Z][A-Za-z0-9_]*)::([A-Za-z_]\w*)\s*(\()?')
CHAIN = re.compile(r'(\$this->getServer\(\)|Server::getInstance\(\)|\$this->getOwningPlugin\(\)->getServer\(\)|\$event->getPlayer\(\)|\$this->getScheduler\(\)|\$this->getLogger\(\)|\$this->getConfig\(\))\s*->\s*([A-Za-z_]\w*)\s*\(')
EXTENDS = re.compile(r'\bclass\s+\w+\s+extends\s+([\\A-Za-z_][\\A-Za-z0-9_]*)')
IMPLEMENTS = re.compile(r'\bclass\s+\w+(?:\s+extends\s+[\\\w]+)?\s+implements\s+([\\\w,\s]+?)\s*\{')
CHAIN_CLASS = {'$this->getServer()': 'pocketmine\\Server', 'Server::getInstance()': 'pocketmine\\Server',
               '$this->getOwningPlugin()->getServer()': 'pocketmine\\Server', '$event->getPlayer()': 'pocketmine\\player\\Player',
               '$this->getScheduler()': 'pocketmine\\scheduler\\TaskScheduler', '$this->getLogger()': 'AttachableLogger',
               '$this->getConfig()': 'pocketmine\\utils\\Config'}

def imports_of(text):
    out = {}
    for m in USE.finditer(text):
        body = m.group(2).strip()
        g = re.match(r'([\\\w]+)\\\{(.*)\}$', body, re.S)
        items = [(g.group(1) + '\\' + x.strip()) for x in g.group(2).split(',')] if g else [x.strip() for x in body.split(',')]
        for item in items:
            parts = re.split(r'\s+as\s+', item.strip().lstrip('\\'), flags=re.I)
            full = parts[0].strip()
            alias = parts[1].strip() if len(parts) > 1 else full.split('\\')[-1]
            out[alias] = full
    return out

class_plugins = collections.defaultdict(set)
method_plugins = collections.defaultdict(set)
static_plugins = collections.defaultdict(set)
extends_plugins = collections.defaultdict(set)
listen_plugins = collections.defaultdict(set)
plugin_classes = {}
features = collections.defaultdict(set)

for row in plugins:
    name = row['project']
    path = os.fspath(root / row['source_dir'])
    used = set()
    for base, _, files in os.walk(path):
        for f in files:
            full = os.path.join(base, f)
            if not f.endswith('.php'):
                continue
            text = Path(full).read_text(encoding='utf-8', errors='replace')
            # strip comments
            text = re.sub(r'/\*.*?\*/', '', text, flags=re.S)
            text = re.sub(r'(?m)(^|\s)(//|#(?!\[)).*$', r'\1', text)
            imp = imports_of(text)
            pm = {a: c for a, c in imp.items() if c.startswith('pocketmine\\')}
            refs = set(pm.values()) | {m.group(1).rstrip('\\') for m in FQ.finditer(text)}
            for c in refs:
                class_plugins[c].add(name)
                used.add(c)
            def resolve(t):
                t = t.lstrip('?')
                if t.startswith('\\'):
                    return t[1:]
                return pm.get(t.split('\\')[0], None) if '\\' not in t else (pm.get(t.split('\\')[0], '') + '\\' + '\\'.join(t.split('\\')[1:]) if t.split('\\')[0] in pm else None)
            vars = {}
            for m in PARAM.finditer(text):
                r = resolve(m.group(1))
                if r and r.startswith('pocketmine\\'):
                    vars[m.group(2)] = r
            for m in re.finditer(r'\$(\w+)\s+instanceof\s+([\\\w]+)', text):
                r = resolve(m.group(2))
                if r and r.startswith('pocketmine\\'):
                    vars.setdefault(m.group(1), r)
            for m in CALL.finditer(text):
                if m.group(1) in vars:
                    method_plugins[vars[m.group(1)] + '->' + m.group(2)].add(name)
            for m in CHAIN.finditer(text):
                method_plugins[CHAIN_CLASS[m.group(1)] + '->' + m.group(2)].add(name)
            for m in STATIC.finditer(text):
                if m.group(1) in pm:
                    static_plugins[pm[m.group(1)] + '::' + m.group(2) + ('()' if m.group(3) else '')].add(name)
            for m in EXTENDS.finditer(text):
                r = resolve(m.group(1))
                if r and r.startswith('pocketmine\\'):
                    extends_plugins[r].add(name)
            for m in IMPLEMENTS.finditer(text):
                for t in m.group(1).split(','):
                    r = resolve(t.strip())
                    if r and r.startswith('pocketmine\\'):
                        extends_plugins[r + ' (implements)'].add(name)
            # event handlers: a public function with one parameter typed as a pocketmine event
            for m in re.finditer(r'public\s+function\s+\w+\s*\(\s*([\\\w]+)\s+\$\w+\s*\)', text):
                r = resolve(m.group(1))
                if r and r.startswith('pocketmine\\event\\') and r.endswith('Event'):
                    listen_plugins[r].add(name)
            if 'pocketmine\\network\\mcpe\\protocol' in text or any(c.startswith('pocketmine\\network\\') for c in refs):
                features['raw network / packets'].add(name)
            if re.search(r'extends\s+\\?(?:pocketmine\\scheduler\\)?AsyncTask\b', text):
                features['AsyncTask'].add(name)
            if re.search(r'pmmp\\thread|\\Thread\b|extends\s+Thread\b|Threaded', text):
                features['threads (pmmpthread)'].add(name)
            if re.search(r'ReflectionClass|ReflectionProperty|->setAccessible\(|Closure::bind', text):
                features['reflection / closure binding'].add(name)
            if re.search(r'curl_|Internet::|file_get_contents\(\s*[\'"]https?', text):
                features['http requests'].add(name)
            if re.search(r'mysqli|PDO|sqlite|libasynql|SQLite3', text):
                features['database'].add(name)
            if re.search(r'sendForm\(|FormAPI|implements\s+Form\b|pocketmine\\form\\', text):
                features['forms'].add(name)
            if re.search(r'InvMenu|muqsit\\invmenu', text):
                features['InvMenu virion'].add(name)
            if re.search(r'CortexPE\\Commando|BaseCommand', text):
                features['Commando virion'].add(name)
    plugin_classes[name] = used

def top(d, n): return sorted(((len(v), k) for k, v in d.items()), reverse=True)[:n]
res = {
 'classes': top(class_plugins, 400), 'methods': top(method_plugins, 400), 'statics': top(static_plugins, 200),
 'extends': top(extends_plugins, 60), 'events': top(listen_plugins, 120),
 'features': sorted(((len(v), k, sorted(v)) for k, v in features.items()), reverse=True),
 'plugin_classes': {k: sorted(v) for k, v in plugin_classes.items()},
}
(output / 'analysis.json').write_text(json.dumps(res, indent=2) + '\n', encoding='utf-8')
