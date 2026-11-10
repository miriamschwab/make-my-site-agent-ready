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

  // A failure, as an object rather than a string, so an agent can never read it as a result.
  // `error` is the site's own message, unchanged: an unknown topic comes back with the topics that
  // do exist, a rate limit with the wait. `code` is the JSON-RPC code, or null when there is none.
  // `retryable` says whether the same call can work later: a rate limit (-32000), or a site that
  // could not be reached or answered with a server error. `kind` says what failed:
  //   tool     the tool ran and reported a problem with the call (MCP's isError). Not retryable as
  //            it is, but the message says what to send instead, so the agent should change the
  //            arguments and call again.
  //   rpc      the server refused the call with a JSON-RPC error.
  //   http     the site answered with something that is not JSON-RPC, such as an HTML error page.
  //   network  the site could not be reached.
  function failure(kind, message, code, retryable) {
    return {
      error: message || 'The site could not run this tool.',
      code: typeof code === 'number' ? code : null,
      retryable: retryable,
      kind: kind
    };
  }

  // A tool's result. Where a tool has structured data, the agent gets it beside the text, under
  // MCP's own name; Chrome passes an object to the agent as a JSON string. A result the tool marked
  // as an error is a failure like any other.
  function outputOf(result) {
    var text = textOf(result);
    if (result && result.isError) return failure('tool', text, null, false);
    var data = result && result.structuredContent;
    return data && typeof data === 'object' ? { text: text, structuredContent: data } : text;
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
        // Not JSON-RPC at all, such as a host's HTML error page. Worth retrying only when the
        // status says the site was busy or broken rather than that the request was wrong.
        return {
          bridgeFailure: failure(
            'http',
            'The site answered with HTTP ' + response.status + ' and no result.',
            null,
            response.status === 429 || response.status >= 500
          )
        };
      });
    }).then(function (message) {
      // Errors are returned, never thrown: Chrome replaces a thrown error's message with a generic
      // "invocation failed", and the site's own message is the useful part.
      if (message && message.bridgeFailure) return message.bridgeFailure;
      if (message && message.error) {
        return failure('rpc', message.error.message, message.error.code, message.error.code === -32000);
      }
      return outputOf(message && message.result);
    }, function () {
      return failure('network', 'The site could not be reached, so the tool did not run.', null, true);
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
