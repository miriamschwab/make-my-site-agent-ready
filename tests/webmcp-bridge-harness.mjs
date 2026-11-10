// Runs assets/webmcp.js as a browser would, with a fake document.modelContext and a fake fetch,
// then calls one registered tool and prints what the bridge returned, as JSON.
//
// Input on stdin: { "endpoint": { "reject": true } | { "status": 200, "body": <object> | <string> } }
// A string body is sent as non-JSON text. Used by tests/WebMcpErrorsTest.php.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import vm from 'node:vm';

const scenario = JSON.parse(readFileSync(0, 'utf8'));
const source = readFileSync(fileURLToPath(new URL('../assets/webmcp.js', import.meta.url)), 'utf8');

const tools = [];
const card = {
  tools: [{
    name: 'list_content',
    description: 'List content.',
    inputSchema: { type: 'object', properties: { topic: { type: 'string' } } },
    annotations: { readOnlyHint: true }
  }]
};

function reply(status, body) {
  const text = typeof body === 'string' ? body : JSON.stringify(body);
  return { ok: status >= 200 && status < 300, status, json: async () => JSON.parse(text) };
}

const context = {
  document: {
    modelContext: { registerTool: (definition) => { tools.push(definition); return Promise.resolve(); } },
    currentScript: { getAttribute: (key) => ({ 'data-endpoint': '/mcp', 'data-card': '/card' }[key] || null) }
  },
  navigator: {},
  location: { origin: 'https://example.test', pathname: '/' },
  fetch: async (url) => {
    if (url === '/card') return reply(200, card);
    if (scenario.endpoint.reject) throw new TypeError('Failed to fetch');
    return reply(scenario.endpoint.status, scenario.endpoint.body);
  },
  JSON, Object, Promise, TypeError
};

vm.runInNewContext(source, context);
for (let i = 0; i < 20 && !tools.length; i++) await new Promise((r) => setImmediate(r));
if (!tools.length) {
  process.stdout.write(JSON.stringify({ harness: 'no tool registered' }));
} else {
  const result = await tools[0].execute({ topic: 'x' });
  process.stdout.write(JSON.stringify({ result }));
}
