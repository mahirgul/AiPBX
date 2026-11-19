(function () {
  'use strict';

  const cfg = window.ASST_CONFIG || {};
  const T = cfg.texts || {};
  const CSRF = cfg.csrf || '';

  let currentChatId = 0;
  let busy = false;

  const mainEl = document.getElementById('asst-main');
  const messagesEl = document.getElementById('asst-messages');
  const inputEl = document.getElementById('asst-input');
  const sendBtn = document.getElementById('asst-send');
  const newChatBtn = document.getElementById('asst-new-chat');

  if (!mainEl || !messagesEl || !inputEl) return;

  function esc(v) {
    return String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[c]));
  }

  function postApi(action, params) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('csrf_token', CSRF);
    Object.entries(params || {}).forEach(([k, v]) => {
      if (v !== undefined && v !== null) fd.append(k, String(v));
    });
    return fetch('/api/assistant.php', {
      method: 'POST',
      body: fd,
      credentials: 'same-origin'
    }).then((r) => r.json());
  }

  function setChatMode(chatting) {
    mainEl.classList.toggle('is-welcome', !chatting);
    mainEl.classList.toggle('is-chatting', chatting);
  }

  function scrollToBottom() {
    messagesEl.scrollTop = messagesEl.scrollHeight;
  }

  function appendUserBubble(text) {
    const wrap = document.createElement('div');
    wrap.className = 'assistant-msg user';
    wrap.innerHTML = '<div class="assistant-bubble">' + esc(text) + '</div>';
    messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  function appendThinking() {
    const wrap = document.createElement('div');
    wrap.className = 'assistant-msg assistant';
    wrap.id = 'asst-thinking-row';
    wrap.innerHTML =
      '<div class="assistant-bubble">' +
        '<span class="assistant-thinking">' +
          esc(T.thinking || 'Thinking') +
          '<span class="assistant-thinking-dots"><span>.</span><span>.</span><span>.</span></span>' +
        '</span>' +
      '</div>';
    messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  function removeThinking() {
    const el = document.getElementById('asst-thinking-row');
    if (el) el.remove();
  }

  function renderInfoBody(payload) {
    const p = payload || {};
    let html = '';
    if (p.title) {
      html += '<div class="u-strong">' + esc(p.title) + '</div>';
    }
    if (Array.isArray(p.rows) && p.rows.length > 0) {
      html += '<div class="u-mt-6 u-overflow-x-auto"><table class="table table-sm u-fs-12 u-mb-0">';
      if (Array.isArray(p.labels) && p.labels.length > 0) {
        html += '<thead><tr>' + p.labels.map((l) => '<th>' + esc(l) + '</th>').join('') + '</tr></thead>';
      }
      html += '<tbody>';
      p.rows.forEach((row) => {
        const vals = Object.values(row || {});
        html += '<tr>' + vals.map((v) => {
          const val = typeof v === 'number' ? (Math.round(v * 10) / 10) : v;
          return '<td>' + esc(val) + '</td>';
        }).join('') + '</tr>';
      });
      html += '</tbody></table></div>';
    }
    if (p.narration) {
      html += '<div class="u-mt-6">' + esc(p.narration) + '</div>';
    }
    return html || esc(p.title || T.err_generic || 'OK');
  }

  function renderNavBody(text, payload) {
    const links = (payload && Array.isArray(payload.links)) ? payload.links : [];
    let html = '<div>' + esc(text || T.nav_intro || '') + '</div>';
    if (links.length > 0) {
      html += '<div class="assistant-nav-links">';
      links.forEach((lnk) => {
        html += '<a class="assistant-nav-link" href="' + esc(lnk.href) + '">' +
          '<i class="fas fa-arrow-right"></i> ' + esc(lnk.label) + '</a>';
      });
      html += '</div>';
    }
    return html;
  }

  function renderUnknownBody(text, payload) {
    const askable = (payload && payload.askable) ? payload.askable : (cfg.echoAskable || '');
    let html = '<div>' + esc(text || T.unknown_intro || '') + '</div>';
    if (askable) {
      html += '<div class="u-fs-12 u-muted u-mt-6">' +
        '<strong>' + esc(T.echo_askable || 'You can ask') + ':</strong> ' + esc(askable) +
        '</div>';
    }
    return html;
  }

  function renderPlanPreview(container, msg) {
    const payload = msg.payload || {};
    const plan = payload.plan || {};
    const argsText = Object.entries(plan.arguments || {})
      .map(([k, v]) => k + ': ' + (Array.isArray(v) ? v.join(', ') : String(v)))
      .join(' · ');
    const risk = plan.risk === 'medium'
      ? ' (' + (T.risk_medium || 'medium') + ')'
      : (plan.risk === 'low' ? '' : (plan.risk ? ' (' + plan.risk + ')' : ''));
    const title = (window.t && plan.title_key) ? window.t(plan.title_key) : (msg.text || plan.title_key || T.preview_title);

    if (msg.plan_id) {
      container.innerHTML =
        '<div class="assistant-preview">' +
          '<div class="u-strong">' + esc(title) + '</div>' +
          '<div class="u-fs-12 u-muted u-mt-6">' + esc(argsText) + '</div>' +
          '<div class="u-mt-8">' +
            '<span class="u-fs-12 u-success u-strong">' + esc(T.applied || 'Applied') + '</span> ' +
            '<button type="button" class="btn btn-secondary u-fs-12 asst-undo-btn" data-plan-id="' + esc(msg.plan_id) + '">' +
              esc(T.undo || 'Undo') +
            '</button>' +
          '</div>' +
        '</div>';
      const undoBtn = container.querySelector('.asst-undo-btn');
      if (undoBtn) {
        undoBtn.addEventListener('click', () => handleUndo(msg.plan_id, undoBtn));
      }
      return;
    }

    container.innerHTML =
      '<div class="assistant-preview">' +
        '<div class="u-strong">' + esc(title) + esc(risk) + '</div>' +
        '<div class="u-fs-12 u-muted u-mt-6">' + esc(argsText) + '</div>' +
        '<div class="u-d-flex u-gap-8 u-mt-10">' +
          '<button type="button" class="btn btn-primary asst-apply-btn">' + esc(T.preview_apply || 'Apply') + '</button>' +
          '<button type="button" class="btn btn-secondary asst-cancel-btn">' + esc(T.preview_discard || 'Cancel') + '</button>' +
        '</div>' +
      '</div>';

    const applyBtn = container.querySelector('.asst-apply-btn');
    const cancelBtn = container.querySelector('.asst-cancel-btn');

    if (cancelBtn) {
      cancelBtn.addEventListener('click', () => {
        container.innerHTML = '<div class="u-fs-12 u-muted">' + esc(T.cancelled || 'Cancelled') + '</div>';
      });
    }

    if (applyBtn) {
      applyBtn.addEventListener('click', () => {
        applyBtn.disabled = true;
        if (cancelBtn) cancelBtn.disabled = true;
        postApi('apply', {
          request: payload.request || '',
          change_action: plan.action || '',
          arguments: JSON.stringify(plan.arguments || {}),
          message_id: msg.id || 0
        }).then((res) => {
          if (!res || !res.success) {
            applyBtn.disabled = false;
            if (cancelBtn) cancelBtn.disabled = false;
            container.insertAdjacentHTML('beforeend',
              '<div class="u-fs-12 u-danger u-mt-6">' + esc((res && res.error) || T.err_generic) + '</div>');
            return;
          }
          let html = '<div class="assistant-preview"><div class="u-strong">' + esc(res.summary || T.applied || 'Applied') + '</div>';
          if (res.undoable) {
            html += '<div class="u-mt-8"><button type="button" class="btn btn-secondary u-fs-12 asst-undo-btn">' +
              esc(T.undo || 'Undo') + '</button></div>';
          }
          html += '</div>';
          container.innerHTML = html;
          const undoBtn = container.querySelector('.asst-undo-btn');
          if (undoBtn) {
            undoBtn.addEventListener('click', () => handleUndo(res.id, undoBtn));
          }
        }).catch(() => {
          applyBtn.disabled = false;
          if (cancelBtn) cancelBtn.disabled = false;
        });
      });
    }
  }

  function handleUndo(planId, btn) {
    if (!btn) return;
    btn.disabled = true;
    postApi('rollback', { id: planId }).then((res) => {
      if (!res || !res.success) {
        btn.disabled = false;
        return;
      }
      btn.parentElement.innerHTML = '<span class="u-fs-12 u-muted">' + esc(T.undo_done || 'Undone') + '</span>';
    }).catch(() => {
      btn.disabled = false;
    });
  }

  function appendAssistantMessage(msg) {
    const wrap = document.createElement('div');
    wrap.className = 'assistant-msg assistant';
    const bubble = document.createElement('div');
    bubble.className = 'assistant-bubble';

    if (msg.kind === 'info') {
      bubble.innerHTML = renderInfoBody(msg.payload);
    } else if (msg.kind === 'nav') {
      bubble.innerHTML = renderNavBody(msg.text, msg.payload);
    } else if (msg.kind === 'unknown') {
      bubble.innerHTML = renderUnknownBody(msg.text, msg.payload);
    } else if (msg.kind === 'plan') {
      renderPlanPreview(bubble, msg);
    } else if (msg.kind === 'settings') {
      bubble.innerHTML = '<div class="u-fw-700 u-mb-8"><i class="fas fa-compass u-primary"></i> ' + esc(msg.text || '') + '</div>'
        + settingsLinksHtml((msg.payload && msg.payload.matches) || []);
    } else if (msg.kind === 'knowledge') {
      const src = ((msg.payload && msg.payload.sources) || []).map((s, i) =>
        '<div>[' + (i + 1) + '] ' + esc(s.title) + ' <span class="u-muted">(' + esc(s.collection) + ')</span></div>').join('');
      bubble.innerHTML = '<div class="u-white-space-pre-wrap">' + esc(msg.text || '') + '</div>' +
        (src ? '<div class="u-fs-12 u-mt-8"><div class="u-strong">' + esc(T.knowledge_sources || 'Sources') + '</div>' + src + '</div>' : '');
    } else {
      bubble.textContent = msg.text || T.err_generic || '';
    }

    wrap.appendChild(bubble);
    messagesEl.appendChild(wrap);
    scrollToBottom();
  }

  function sendQuestion(text) {
    const q = String(text || '').trim();
    if (!q || busy) return;
    busy = true;
    if (newChatBtn) newChatBtn.disabled = true;
    inputEl.value = '';
    setChatMode(true);
    appendUserBubble(q);
    appendThinking();

    postApi('ask', { question: q, chat_id: currentChatId }).then((res) => {
      removeThinking();
      if (!res || !res.success || !res.message) {
        appendAssistantMessage({ kind: 'error', text: (res && res.error) || T.err_generic });
        return;
      }
      currentChatId = Number(res.chat_id || currentChatId);
      appendAssistantMessage(res.message);
    }).catch(() => {
      removeThinking();
      appendAssistantMessage({ kind: 'error', text: T.err_generic || 'Error' });
    }).finally(() => {
      busy = false;
      if (newChatBtn) newChatBtn.disabled = false;
    });
  }

  if (sendBtn) {
    sendBtn.addEventListener('click', () => sendQuestion(inputEl.value));
  }
  inputEl.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      sendQuestion(inputEl.value);
    }
  });

  document.querySelectorAll('.assistant-suggestion').forEach((btn) => {
    btn.addEventListener('click', () => {
      const q = btn.getAttribute('data-suggestion') || '';
      sendQuestion(q);
    });
  });

  if (newChatBtn) {
    newChatBtn.addEventListener('click', () => {
      currentChatId = 0;
      messagesEl.innerHTML = '';
      setChatMode(false);
      inputEl.focus();
    });
  }

  // Links to the pages and settings a search found (sidebar search and the chat's "settings" answers).
  function settingsLinksHtml(matches) {
    let html = '<div class="u-d-flex u-col u-gap-6">';
    matches.forEach((m) => {
      const snippets = Array.isArray(m.snippets) && m.snippets.length
        ? '<div class="u-fs-11 u-muted u-mt-2">' + m.snippets.map(esc).join(' • ') + '</div>'
        : '';
      html += '<a href="' + esc(m.href) + '" class="assistant-result-link">'
        + '<div class="u-flex-between">'
        + '<span class="u-fw-700"><i class="' + esc(m.icon || 'fas fa-sliders-h') + ' u-primary"></i> ' + esc(m.title) + '</span>'
        + '<span class="badge badge-secondary u-fs-10">' + esc(m.group) + '</span>'
        + '</div>'
        + snippets
        + '</a>';
    });
    return html + '</div>';
  }

  function renderSettingsSearchResults(query, matches) {
    setChatMode(true);
    appendUserBubble(query);

    const wrap = document.createElement('div');
    wrap.className = 'assistant-msg assistant';
    const bubble = document.createElement('div');
    bubble.className = 'assistant-bubble';

    let html = '<div class="u-fw-700 u-mb-8"><i class="fas fa-compass u-primary"></i> '
      + esc(T.nav_intro || 'Settings shortcuts') + ' (<code>' + esc(query) + '</code>)</div>';

    if (Array.isArray(matches) && matches.length > 0) {
      html += settingsLinksHtml(matches);
    } else {
      html += '<div class="u-fs-12 u-muted">' + esc(T.no_settings_results || '') + '</div>';
    }

    html += '<div class="u-mt-10"><button type="button" class="btn btn-secondary btn-sm" id="asst-ask-ai-search">'
      + '<i class="fas fa-wand-magic-sparkles"></i> ' + esc(T.ask || 'Ask AI') + '</button></div>';

    bubble.innerHTML = html;
    wrap.appendChild(bubble);
    messagesEl.appendChild(wrap);
    scrollToBottom();

    const askBtn = bubble.querySelector('#asst-ask-ai-search');
    if (askBtn) {
      askBtn.addEventListener('click', () => {
        askBtn.disabled = true;
        sendQuestion(query);
      });
    }
  }

  if (cfg.initialQuery) {
    renderSettingsSearchResults(cfg.initialQuery, cfg.settingsMatches || []);
  }
})();
