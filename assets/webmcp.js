/**
 * Make My Site Agent-Ready: WebMCP bridge.
 *
 * Registers this site's read-only MCP tools with an agent working in the browser, through
 * document.modelContext. Loaded only by the inline loader, and only where the browser has the API.
 *
 * Nothing here implements a tool. Names, descriptions and input schemas come from the site's MCP
 * server card, and every call goes to the MCP endpoint, so search, the noindex and password rules,
 * and the rate limit all stay on the server.
 */
(function () {
  'use strict';

  var mc = document.modelContext || navigator.modelContext;
  if (!mc || typeof mc.registerTool !== 'function') return;

  var script = document.currentScript;
  var endpoint = script && script.getAttribute('data-endpoint');
  var cardUrl = script && script.getAttribute('data-card');
  if (!endpoint || !cardUrl) return;

  var nextId = 0;

  // The tool's own text, joined. MCP results are a list of content parts; these tools only ever
  // return text, and an agent reading the result wants the text, not the envelope around it.
  function textOf(result) {
    var parts = (result && result.content) || [];
    return parts
      .filter(function (p) { return p && p.type === 'text' && typeof p.text === 'string'; })
      .map(function (p) { return p.text; })
      .join('\n\n');
  }

  function call(name, args) {
    return fetch(endpoint, {
      method: 'POST',
      // Public, read-only tools: the visitor's cookies are never needed and never sent.
      credentials: 'omit',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        // Labels the route in the site's agent log. It grants nothing.
        'X-MMSAR-Surface': 'webmcp'
      },
      body: JSON.stringify({
        jsonrpc: '2.0',
        id: ++nextId,
        method: 'tools/call',
        params: { name: name, arguments: args || {} }
      })
    }).then(function (response) {
      return response.json().catch(function () {
        return { error: { message: 'The site answered with HTTP ' + response.status + ' and no result.' } };
      });
    }).then(function (message) {
      // Errors are returned as text, never thrown. Chrome replaces a thrown error's message with a
      // generic "invocation failed", and the site's own message is the useful part: an unknown
      // topic comes back with the list of topics that do exist, a rate limit with the wait.
      if (message && message.error) {
        return message.error.message || 'The site could not run this tool.';
      }
      var result = message && message.result;
      return textOf(result) || (result && result.isError ? 'The site could not run this tool.' : '');
    }, function () {
      return 'The site could not be reached, so the tool did not run.';
    });
  }

  // In a browser the page the person is on is the obvious default for get_content, which the
  // server has no way of knowing. Only the browser copy of the schema changes.
  function adapt(tool) {
    if (tool.name !== 'get_content') return tool;
    var schema = JSON.parse(JSON.stringify(tool.inputSchema || {}));
    schema.required = (schema.required || []).filter(function (key) { return key !== 'url'; });
    return Object.assign({}, tool, {
      inputSchema: schema,
      description: (tool.description || '') + ' Omit url to read the page the user is on.'
    });
  }

  function register(raw) {
    var tool = adapt(raw);
    var definition = {
      name: tool.name,
      title: tool.title || tool.name,
      description: tool.description || '',
      inputSchema: tool.inputSchema || { type: 'object', properties: {} },
      annotations: {
        readOnlyHint: true,
        // What these tools return is this website's published text. To the agent, that is web
        // content it did not write, and it should treat it as data rather than instructions.
        untrustedContentHint: true
      },
      execute: function (args) {
        args = Object.assign({}, args || {});
        if (tool.name === 'get_content' && !args.url) {
          args.url = location.origin + location.pathname;
        }
        return call(tool.name, args);
      }
    };
    try {
      var pending = mc.registerTool(definition);
      // Each registration stands alone: one that is refused (a name already taken on this page,
      // say) must not stop the rest.
      if (pending && typeof pending.catch === 'function') pending.catch(function () {});
    } catch (e) { /* Same: skip this tool, keep the others. */ }
  }

  fetch(cardUrl, { credentials: 'omit' })
    .then(function (response) { return response.ok ? response.json() : null; })
    .then(function (card) {
      ((card && card.tools) || []).forEach(function (tool) {
        if (tool && tool.name && tool.annotations && tool.annotations.readOnlyHint === true) {
          register(tool);
        }
      });
    })
    .catch(function () { /* No card, no tools. The page itself is unaffected. */ });
})();
