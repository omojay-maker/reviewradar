(() => {
  const pages = new Set(['overview', 'reviews', 'themes', 'alerts', 'reports', 'sources', 'settings', 'onboarding']);
  const page = location.pathname.split('/').pop() || 'index';
  const api = async (path, options = {}) => {
    const { headers: suppliedHeaders = {}, ...fetchOptions } = options;
    const isFormData = typeof FormData !== 'undefined' && options.body instanceof FormData;
    const response = await fetch(`api/${path}`, {
      credentials: 'same-origin',
      ...fetchOptions,
      headers: { ...(options.body && !isFormData ? { 'Content-Type': 'application/json' } : {}), ...suppliedHeaders },
    });
    let payload;
    try { payload = await response.json(); } catch { payload = {}; }
    if (!response.ok || !payload.success) {
      const error = new Error(payload.error?.message || `Request failed (${response.status}).`);
      error.status = response.status;
      throw error;
    }
    return payload.data;
  };
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);
  const message = (form, text, failed = false) => {
    let node = form.querySelector('[data-form-message]');
    if (!node) {
      node = document.createElement('p');
      node.dataset.formMessage = '1';
      node.setAttribute('role', 'status');
      form.append(node);
    }
    node.className = failed ? 'rr-error' : 'rr-success';
    node.textContent = text;
  };
  const date = (value) => {
    if (!value) return '—';
    const parsed = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
    return Number.isNaN(parsed.valueOf()) ? String(value) : parsed.toLocaleString();
  };
  const sentimentPill = (value) => `<span class="pill ${value === 'positive' ? 'pos' : value === 'negative' ? 'neg' : 'neu'}">${esc(value || 'neutral')}</span>`;
  const tagList = (items = []) => items.length ? items.map((item) => `<span class="chip">${esc(item)}</span>`).join('') : '<span class="muted">No topics identified</span>';

  const signup = document.querySelector('#company')?.closest('form');
  if (signup) {
    signup.querySelector('button[type="button"]')?.setAttribute('type', 'submit');
    signup.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = signup.querySelector('button[type="submit"]');
      button.disabled = true;
      try {
        await api('signup.php', { method: 'POST', body: JSON.stringify({ company_name: document.querySelector('#company').value, email: document.querySelector('#email').value, password: document.querySelector('#password').value }) });
        location.href = 'overview';
      } catch (error) { message(signup, error.message, true); button.disabled = false; }
    });
  }
  const login = document.querySelector('#email')?.closest('form');
  if (login && !signup) {
    login.querySelector('button[type="button"]')?.setAttribute('type', 'submit');
    login.addEventListener('submit', async (event) => {
      event.preventDefault();
      const button = login.querySelector('button[type="submit"]');
      button.disabled = true;
      try {
        await api('login.php', { method: 'POST', body: JSON.stringify({ email: document.querySelector('#email').value, password: document.querySelector('#password').value }) });
        location.href = 'overview';
      } catch (error) { message(login, error.message, true); button.disabled = false; }
    });
  }

  if (!pages.has(page)) return;
  const main = document.querySelector('main');
  if (!main) return;

  const sidebar = document.querySelector('.rr-sidebar');
  if (sidebar) {
    if (!sidebar.id) sidebar.id = 'rr-sidebar';
    const menuButton = document.createElement('button');
    menuButton.className = 'rr-menu-btn';
    menuButton.type = 'button';
    menuButton.setAttribute('aria-label', 'Open navigation menu');
    menuButton.setAttribute('aria-controls', sidebar.id);
    menuButton.setAttribute('aria-expanded', 'false');
    menuButton.innerHTML = '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg>';
    const backdrop = document.createElement('div');
    backdrop.className = 'rr-backdrop';
    backdrop.setAttribute('aria-hidden', 'true');
    const setMenu = (open) => {
      sidebar.classList.toggle('is-open', open);
      backdrop.classList.toggle('is-open', open);
      menuButton.classList.toggle('is-hidden', open);
      menuButton.setAttribute('aria-expanded', String(open));
      document.body.classList.toggle('menu-open', open);
    };
    menuButton.addEventListener('click', () => setMenu(true));
    backdrop.addEventListener('click', () => setMenu(false));
    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setMenu(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 820) setMenu(false); });
    document.body.append(menuButton, backdrop);
  }

  const logout = document.createElement('button');
  logout.className = 'btn sm alt rr-logout';
  logout.type = 'button';
  logout.textContent = 'Log out';
  logout.addEventListener('click', async () => {
    try { await api('logout.php', { method: 'POST', body: '{}' }); } catch {}
    location.href = 'login';
  });
  document.body.append(logout);

  const panel = (title, content, extra = '') => `<section class="rr-panel ${extra}"><h2 class="h2">${title}</h2>${content}</section>`;
  const header = (title, subtitle, action = '') => `<header class="rr-page-head"><div><h1 class="disp">${title}</h1><p class="muted">${subtitle}</p></div>${action}</header>`;
  const companyNav = (company) => {
    const name = String(company.name ?? '').trim();
    document.querySelectorAll('nav > div').forEach((group) => {
      const avatar = group.querySelector('span.disp');
      const label = group.querySelector('div > div') || group.querySelector('div');
      if (!avatar || !label) return;
      avatar.textContent = name.charAt(0).toUpperCase() || '?';
      label.textContent = name;
    });
  };
  const alertAckKey = (company) => `rr.alertAck.${company.id}`;
  const readAlertAck = (company) => {
    try { return Number(window.localStorage.getItem(alertAckKey(company))) || 0; } catch { return 0; }
  };
  const writeAlertAck = (company, count) => {
    try { window.localStorage.setItem(alertAckKey(company), String(count)); } catch {}
  };
  const updateAlertBadge = (company, urgentCount, forceHidden = false) => {
    const alertBadge = document.querySelector('[data-alert-badge]');
    if (!alertBadge) return;
    const unread = Math.max(0, urgentCount - readAlertAck(company));
    alertBadge.textContent = unread.toLocaleString();
    alertBadge.hidden = forceHidden || unread === 0;
  };
  const loadReviews = async (limit = 50, offset = 0) => api(`get_reviews.php?limit=${limit}&offset=${offset}`);
  const renderReview = (review) => `<article class="rr-review">${sentimentPill(review.sentiment)}${review.requires_action ? '<span class="urg">Urgent</span>' : ''}<span class="muted rr-review-date">${esc(review.source)} · ${esc(date(review.created_at))}</span><p>${esc(review.raw_text)}</p><div class="rr-review-meta"><div class="rr-tags">${tagList(review.categories)}</div><span class="muted">${review.rating ? `${esc(review.rating)} of 5` : 'No rating'}</span></div><div class="muted rr-summary">${esc(review.summary)}</div></article>`;

  const render = async (analytics, company) => {
    const t = analytics.totals;
    const usage = analytics.usage;
    companyNav(company);

    const urgentCount = Number(t.urgent_actions) || 0;
    updateAlertBadge(company, urgentCount);

    if (page === 'overview') {
      const days = analytics.range.days;
      const trend = analytics.trend.slice(-14);
      const peak = Math.max(1, ...trend.map((item) => item.reviews));
      const bars = trend.map((item) => `<div class="rr-bar-item" title="${esc(item.date)}: ${item.reviews} reviews"><div class="rr-bar" style="height:${Math.max(item.reviews ? 4 : 2, Math.round(item.reviews / peak * 130))}px"></div><small>${esc(item.date.slice(5))}</small></div>`).join('');
      const breakdown = analytics.sentiment_breakdown;
      const urgent = analytics.recent_urgent_reviews;
      main.innerHTML = `${header('Overview', `${esc(company.name)} · last ${days} days`, `<label class="rr-range">Date range <select id="rr-days" class="select"><option value="7" ${days === 7 ? 'selected' : ''}>7 days</option><option value="30" ${days === 30 ? 'selected' : ''}>30 days</option><option value="90" ${days === 90 ? 'selected' : ''}>90 days</option></select></label>`)}
        <div class="rr-kpis"><div class="rr-panel"><span class="muted">Reviews</span><strong>${t.reviews}</strong></div><div class="rr-panel"><span class="muted">Average sentiment</span><strong>${t.average_score > 0 ? '+' : ''}${Number(t.average_score).toFixed(2)}</strong></div><div class="rr-panel"><span class="muted">Needs attention</span><strong>${t.urgent_actions}</strong></div><div class="rr-panel"><span class="muted">Average rating</span><strong>${t.average_rating ?? '—'}${t.average_rating === null ? '' : ' / 5'}</strong></div></div>
        <div class="rr-two-col">${panel('Review volume', `<p class="muted">Daily reviews · latest ${trend.length} days</p><div class="rr-chart">${bars || '<p class="muted">No reviews in this period.</p>'}</div>`)}${panel('Sentiment', `<div class="rr-sentiments"><div>${sentimentPill('positive')}<b>${breakdown.positive}</b></div><div>${sentimentPill('neutral')}<b>${breakdown.neutral}</b></div><div>${sentimentPill('negative')}<b>${breakdown.negative}</b></div></div><h3>Top complaint topics</h3><div class="rr-tags">${tagList(analytics.top_complaint_categories.map((item) => `${item.category} (${item.count})`))}</div>`)}</div>
        ${panel('Recent urgent reviews', urgent.length ? `<div class="rr-list">${urgent.slice(0, 5).map((review) => `<div class="rr-urgent-item">${renderReview(review)}</div>`).join('')}</div><a class="btn sm alt" href="alerts">View urgent reviews</a>` : '<p class="muted">No reviews have been flagged as urgent in this period.</p>')}`;
      document.querySelector('#rr-days').addEventListener('change', async (event) => {
        try { const next = await api(`get_analytics.php?days=${Number(event.target.value)}`); await render(next, company); }
        catch (error) { if (error.status === 401) location.href = 'login'; }
      });
      return;
    }

    if (page === 'reviews') {
      let offset = 0;
      let current = await loadReviews(50, offset);
      const draw = () => {
        const pageCount = Math.max(1, Math.ceil(current.count / current.limit));
        main.innerHTML = `${header('Reviews', `Showing ${offset + 1}–${Math.min(offset + current.reviews.length, current.count)} of ${current.count} company reviews`, '<a class="btn" href="sources">Import reviews</a>')}<div class="rr-controls"><input id="rr-search" class="inp md" type="search" placeholder="Search reviews and topics"><select id="rr-sentiment" class="select"><option value="all">All sentiment</option><option value="positive">Positive</option><option value="neutral">Neutral</option><option value="negative">Negative</option></select></div><div id="rr-review-results" class="rr-list"></div><div class="rr-pagination"><button id="rr-prev" class="btn sm alt" ${offset === 0 ? 'disabled' : ''}>Previous</button><span class="muted">Page ${Math.floor(offset / current.limit) + 1} of ${pageCount}</span><button id="rr-next" class="btn sm alt" ${offset + current.limit >= current.count ? 'disabled' : ''}>Next</button></div>`;
        const filterRows = () => {
          const filter = document.querySelector('#rr-search').value.toLowerCase();
          const sentiment = document.querySelector('#rr-sentiment').value;
          const matching = current.reviews.filter((review) => (sentiment === 'all' || review.sentiment === sentiment) && `${review.raw_text} ${review.summary} ${review.categories.join(' ')}`.toLowerCase().includes(filter));
          document.querySelector('#rr-review-results').innerHTML = matching.length ? matching.map(renderReview).join('') : '<p class="muted">No matching reviews on this page.</p>';
        };
        filterRows();
        document.querySelector('#rr-search').addEventListener('input', filterRows);
        document.querySelector('#rr-sentiment').addEventListener('change', filterRows);
        document.querySelector('#rr-prev').addEventListener('click', async () => { offset = Math.max(0, offset - current.limit); current = await loadReviews(current.limit, offset); draw(); });
        document.querySelector('#rr-next').addEventListener('click', async () => { offset += current.limit; current = await loadReviews(current.limit, offset); draw(); });
      };
      draw();
      return;
    }

    if (page === 'alerts') {
      const urgent = analytics.recent_urgent_reviews;
      main.innerHTML = `${header('Alerts', `${t.urgent_actions} reviews need attention · latest ${urgent.length} urgent items`)}${panel('Urgent reviews', urgent.length ? `<div class="rr-list">${urgent.map(renderReview).join('')}</div>` : '<p class="muted">No urgent reviews in the selected period.</p>')}<div class="rr-callout">Urgency is generated by review analysis. Email, Slack delivery, assignment, and handled status are not configured.</div>`;
      writeAlertAck(company, urgentCount);
      updateAlertBadge(company, urgentCount, true);
      return;
    }

    if (page === 'themes') {
      const data = await loadReviews(250, 0);
      const topics = new Map();
      for (const review of data.reviews) for (const category of review.categories) {
        const row = topics.get(category) || { total: 0, positive: 0, neutral: 0, negative: 0 };
        row.total += 1;
        row[review.sentiment] += 1;
        topics.set(category, row);
      }
      const rows = [...topics.entries()].sort((a, b) => b[1].total - a[1].total).map(([name, value]) => `<div class="rr-topic-row"><b>${esc(name)}</b><span>${value.total} mentions</span><span>+${value.positive} / ~${value.neutral} / −${value.negative}</span></div>`).join('');
      main.innerHTML = `${header('Themes', `Topics extracted from the latest ${data.reviews.length} of ${data.count} reviews`)}${panel('Customer topics', rows ? `<div class="rr-topic-list">${rows}</div>` : '<p class="muted">Topics will appear here after reviews are analyzed.</p>')}<div class="rr-callout">Topic counts cover the latest 250 reviews. The saved review and sentiment data remain scoped to ${esc(company.name)}.</div>`;
      return;
    }

    if (page === 'reports') {
      const trendRows = analytics.trend.filter((item) => item.reviews > 0).map((item) => `<div class="rr-topic-row"><b>${esc(item.date)}</b><span>${item.reviews} reviews</span><span>Average sentiment ${item.average_score === null ? '—' : Number(item.average_score).toFixed(2)}</span></div>`).join('');
      main.innerHTML = `${header('Reports', `Analysis for ${esc(company.name)} · ${analytics.range.days} days`, '<a class="btn" href="api/export_reviews.php">Download all reviews as CSV</a>')}<div class="rr-kpis"><div class="rr-panel"><span class="muted">Positive</span><strong>${analytics.sentiment_breakdown.positive}</strong></div><div class="rr-panel"><span class="muted">Neutral</span><strong>${analytics.sentiment_breakdown.neutral}</strong></div><div class="rr-panel"><span class="muted">Negative</span><strong>${analytics.sentiment_breakdown.negative}</strong></div><div class="rr-panel"><span class="muted">Urgent</span><strong>${t.urgent_actions}</strong></div></div>${panel('Daily summary', trendRows ? `<div class="rr-topic-list">${trendRows}</div>` : '<p class="muted">No reviews in this period.</p>')}`;
      return;
    }

    if (page === 'sources') {
      main.innerHTML = `${header('Sources', 'Upload a review export and ReviewRadar will analyze every row.')}
        ${panel('Import reviews', `<div class="rr-import"><p class="muted">Choose a file exported from your review tool. Supported: CSV, TSV, JSON, PDF, XLSX, DOCX, TXT and HTML. Up to 1,000 reviews and 20 MB per file.</p><form id="rr-import-form" class="rr-form"><input class="rr-file" id="rr-import-file" type="file" accept=".csv,.tsv,.json,.pdf,.xlsx,.docx,.txt,.html,.htm,.md,text/csv,text/tab-separated-values,application/json,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/plain,text/html" required><button class="btn" type="submit">Import reviews</button></form><div id="rr-import-progress" class="rr-progress" hidden><div class="rr-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"><span id="rr-import-bar"></span></div><div class="rr-progress-meta"><span id="rr-import-label">Uploading…</span><span id="rr-import-count"></span></div></div><div id="rr-import-status" role="status" class="muted"></div></div>`)}`;
      const form = document.querySelector('#rr-import-form');
      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const input = document.querySelector('#rr-import-file');
        const button = form.querySelector('button[type="submit"]');
        const status = document.querySelector('#rr-import-status');
        const progress = document.querySelector('#rr-import-progress');
        const bar = document.querySelector('#rr-import-bar');
        const label = document.querySelector('#rr-import-label');
        const count = document.querySelector('#rr-import-count');
        const progressTrack = progress.querySelector('[role="progressbar"]');
        const file = input.files?.[0];
        if (!file) return;

        const setProgress = (percent, text, detail = '') => {
          const value = Math.max(0, Math.min(100, Math.round(percent)));
          bar.style.width = `${value}%`;
          progressTrack.setAttribute('aria-valuenow', String(value));
          label.textContent = text;
          count.textContent = detail;
        };

        button.disabled = true;
        progress.hidden = false;
        progress.classList.remove('rr-progress-error');
        status.className = 'muted';
        status.textContent = '';
        setProgress(3, 'Uploading and reading the file…', file.name);

        try {
          const body = new FormData();
          body.append('file', file);
          const parsed = await api('parse_import.php', { method: 'POST', body });
          const rows = parsed.rows || [];
          if (rows.length === 0) throw new Error('No reviews were found in the import file.');
          if (rows.length > 1000) throw new Error('Import at most 1,000 reviews per file.');

          setProgress(10, `Found ${rows.length.toLocaleString()} review${rows.length === 1 ? '' : 's'}. Starting analysis…`, `0 of ${rows.length.toLocaleString()}`);

          const batchSize = 25;
          let imported = 0;
          let failed = 0;
          let stopped = false;
          const errors = [];

          for (let start = 0; start < rows.length; start += batchSize) {
            const batch = rows.slice(start, start + batchSize);
            const processed = Math.min(start + batch.length, rows.length);
            setProgress(10 + (start / rows.length) * 90, `Analyzing reviews ${processed - batch.length + 1}–${processed}…`, `${start} of ${rows.length} processed`);
            try {
              const result = await api('import_reviews.php', { method: 'POST', body: JSON.stringify({ reviews: batch }) });
              imported += result.imported;
              failed += result.failed;
              for (const item of result.errors || []) errors.push(`Row ${start + item.row}: ${item.message}`);
            } catch (error) {
              if (error.status === 401) { location.href = 'login'; return; }
              failed += batch.length;
              errors.push(error.message);
              stopped = true;
              break;
            }
            setProgress(10 + (processed / rows.length) * 90, `Imported ${imported.toLocaleString()} review${imported === 1 ? '' : 's'}…`, `${processed} of ${rows.length} processed`);
          }

          setProgress(100, 'Import complete.', `${rows.length} of ${rows.length} processed`);
          const summary = `Imported ${imported.toLocaleString()} review${imported === 1 ? '' : 's'}${failed ? `, ${failed.toLocaleString()} failed` : ''}${stopped ? ' Import stopped after an error.' : ''}${errors.length ? ` ${errors.slice(0, 3).join(' ')}` : ''}`;
          const hadError = failed > 0 || stopped;
          status.className = hadError ? 'rr-error' : 'rr-success';
          status.textContent = summary;
          if (hadError) progress.classList.add('rr-progress-error');
        } catch (error) {
          if (error.status === 401) { location.href = 'login'; return; }
          setProgress(100, 'Import failed.', '');
          progress.classList.add('rr-progress-error');
          status.className = 'rr-error';
          status.textContent = error.message;
        } finally {
          button.disabled = false;
          input.value = '';
        }
      });
      return;
    }

    if (page === 'settings') {
      main.innerHTML = `${header('Settings', 'Company workspace and account access')}
        ${panel('Company account', `<div class="rr-topic-list"><div class="rr-topic-row"><b>Company</b><span>${esc(company.name)}</span></div><div class="rr-topic-row"><b>Account email</b><span>${esc(company.email)}</span></div></div>`)}
        ${panel('Workspace access', '<p>Each company account currently has one owner login. Team invitations and role management are not enabled.</p>')}
        ${panel('Delete workspace', `<p class="muted">Permanently deletes this company account and all of its reviews.</p><form id="rr-delete-form" class="rr-form"><label class="lbl" for="rr-delete-name">Type ${esc(company.name)} to confirm</label><input class="inp md" id="rr-delete-name" required autocomplete="off"><label class="lbl" for="rr-delete-password">Account password</label><input class="inp md" id="rr-delete-password" type="password" required autocomplete="current-password"><button class="btn" type="submit">Delete company workspace</button></form>`)}`;
      document.querySelector('#rr-delete-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!window.confirm('This permanently deletes the company and all its reviews. Continue?')) return;
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
          const result = await api('delete_account.php', { method: 'POST', body: JSON.stringify({ company_name: document.querySelector('#rr-delete-name').value, password: document.querySelector('#rr-delete-password').value }) });
          window.alert(result.message);
          location.href = 'index';
        } catch (error) { message(form, error.message, true); button.disabled = false; }
      });
      return;
    }

    main.innerHTML = `${header('Welcome to ReviewRadar', `Your ${esc(company.name)} workspace is ready.`)}<p>Start by importing a review export from CSV, PDF, JSON or another supported format.</p><a class="btn" href="sources">Import reviews</a>`;
  };

  (async () => {
    try {
      const analytics = await api('get_analytics.php?days=30');
      await render(analytics, analytics.company);
    } catch (error) {
      if (error.status === 401) { location.href = 'login'; return; }
      main.innerHTML = `<div class="rr-panel"><h1 class="h2">Workspace unavailable</h1><p class="rr-error">${esc(error.message)}</p><button id="rr-retry" class="btn sm alt">Retry</button></div>`;
      document.querySelector('#rr-retry').addEventListener('click', () => location.reload());
    }
  })();
})();
