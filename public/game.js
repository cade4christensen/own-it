(function () {
  'use strict';

  // Three ways in: 'player' on a phone, 'host' with the controls, 'screen' for a projector with no controls.
  const MODE = window.OWNIT_MODE || 'player';
  const HOST = MODE === 'host', SCREEN = MODE === 'screen', BIG = HOST || SCREEN;
  const AVATARS = window.OWNIT_AVATARS || {};
  const AVATAR_KEYS = Object.keys(AVATARS);
  const MAX_ANSWER = 160, MAX_NICK = 20;
  const stage = document.getElementById('stage');
  const hostbar = document.getElementById('hostbar');
  const hostbarInner = document.getElementById('hostbar-inner');
  const metaEl = document.getElementById('meta');
  const toastEl = document.getElementById('toast');

  const ls = {
    get(k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { window.localStorage.setItem(k, v); } catch (e) {} },
  };

  function newToken() {
    const bytes = new Uint8Array(16);
    window.crypto.getRandomValues(bytes);
    return Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
  }
  let token = ls.get('ownit_token');
  if (!token) { token = newToken(); ls.set('ownit_token', token); }

  const S = {
    st: null, offline: false, skew: 0,
    hostKey: HOST ? (ls.get('ownit_host_key') || '') : '',
    nick: ls.get('ownit_nick') || '',
    avatar: ls.get('ownit_avatar') || AVATAR_KEYS[Math.floor(Math.random() * AVATAR_KEYS.length)] || '',
    editing: false, editAns: false, confirmRestart: false, joining: false,
  };

  /* ---------- helpers ---------- */
  function el(tag, props, kids) {
    const n = document.createElement(tag);
    if (props) for (const k in props) {
      const v = props[k];
      if (v == null || v === false) continue;
      if (k === 'class') n.className = v;
      else if (k === 'text') n.textContent = v;
      else if (k === 'value') n.value = v;
      else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
      else n.setAttribute(k, v === true ? '' : v);
    }
    if (kids != null) [].concat(kids).forEach((c) => { if (c != null && c !== false) n.append(c); });
    return n;
  }
  let toastTimer = null;
  function toast(msg) {
    toastEl.textContent = msg; toastEl.hidden = false;
    clearTimeout(toastTimer); toastTimer = setTimeout(() => { toastEl.hidden = true; }, 5000);
  }
  function fail(e) { toast((e && e.message) || "That didn't save. Check your connection and try again."); }
  const plural = (n, word) => n + ' ' + word + (n === 1 ? '' : 's');
  // An avatar is an emoji, or an image when its config value is a path.
  function avatar(key, big) {
    const v = AVATARS[key];
    if (!v) return null;
    const node = el('span', { class: 'avatar' + (big ? ' big' : ''), 'aria-hidden': 'true' });
    if (v.charAt(0) === '/') node.append(el('img', { src: v, alt: '' }));
    else node.textContent = v;
    return node;
  }

  /* ---------- server ---------- */
  let seq = 0, applied = 0;
  function api(method, path, body) {
    const mine = ++seq;
    return fetch('/api/' + path, {
      method,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-Player': MODE === 'player' ? token : '', 'X-Host-Key': S.hostKey },
      body: body ? JSON.stringify(body) : undefined,
    }).then((res) => res.json().catch(() => ({})).then((data) => {
      if (!res.ok) throw { status: res.status, message: data.message || '' };
      if (mine > applied) { applied = mine; apply(data); }
      return data;
    }));
  }
  function apply(st) {
    const prev = S.st;
    if (prev && (prev.game.id !== st.game.id || prev.game.round !== st.game.round || prev.game.phase !== st.game.phase)) {
      S.editAns = false; S.confirmRestart = false;
    }
    S.st = st; S.offline = false; S.skew = st.now - Date.now();
    render();
    // A new game keeps everyone's nickname, so nobody has to rejoin by hand.
    if (MODE === 'player' && S.nick && !st.me && !S.joining) join(S.nick);
  }
  function poll() {
    api('GET', 'state').catch(() => { S.offline = true; render(); })
      .then(() => setTimeout(poll, document.hidden ? 5000 : 1500));
  }

  function join(nick) {
    nick = (nick || '').trim().slice(0, MAX_NICK);
    if (!nick || S.joining) return;
    S.joining = true;
    const pick = AVATARS[S.avatar] ? S.avatar : null;
    api('POST', 'join', { nick, avatar: pick }).then(() => { S.nick = nick; ls.set('ownit_nick', nick); ls.set('ownit_avatar', S.avatar); })
      .catch(fail).then(() => { S.joining = false; render(); });
  }
  function submitAnswer(text, btn) {
    text = (text || '').trim().slice(0, MAX_ANSWER);
    if (!text) { toast('Write your version first.'); return; }
    if (btn) btn.disabled = true;
    S.editAns = false;
    api('POST', 'answer', { text }).catch((e) => { S.editAns = true; fail(e); })
      .then(() => { if (btn) btn.disabled = false; render(); });
  }
  const vote = (id) => api('POST', 'vote', { answer_id: id }).catch(fail);
  const advance = (action) => api('POST', 'host/advance', { action }).catch(fail);
  const removeAnswer = (id) => api('POST', 'host/remove', { answer_id: id }).catch(fail);
  function savePrompts(raw) {
    const items = raw.split('\n').map((line) => {
      const parts = line.split('::').map((part) => part.trim());
      return { setup: parts[0] || '', line: parts[1] || '', ask: parts[2] || '', value: parts[3] || '', point: parts.slice(4).join(' ') };
    }).filter((p) => p.setup || p.line);
    api('POST', 'host/prompts', { items }).then(() => { S.editing = false; render(); }).catch(fail);
  }
  function tryHostKey(key) {
    S.hostKey = key.trim();
    api('GET', 'state').then((st) => {
      if (st.isHost) ls.set('ownit_host_key', S.hostKey);
      else toast('That host key is not right.');
    }).catch(fail);
  }

  /* ---------- views ---------- */
  let dyn = [];
  function textDyn(node, fn) { dyn.push(() => { const t = fn(); if (node.textContent !== t) node.textContent = t; }); return node; }
  function listDyn(node, sigFn, buildFn) {
    let sig = null;
    dyn.push(() => { const s = sigFn(); if (s !== sig) { sig = s; node.replaceChildren(...buildFn()); } });
    return node;
  }
  const timerNode = () => el('span', { class: 'timer', 'data-timer': true });
  function tickTimers() {
    const ends = S.st ? S.st.game.endsAt : 0;
    const left = Math.ceil((ends - (Date.now() + S.skew)) / 1000);
    const t = !ends ? '' : left <= 0 ? "Time's up" : Math.floor(left / 60) + ':' + String(left % 60).padStart(2, '0');
    document.querySelectorAll('[data-timer]').forEach((n) => { if (n.textContent !== t) n.textContent = t; });
  }

  // A quote with no question of its own is an excuse to rewrite.
  const isExcuse = (p) => !!p.line && !p.ask;
  const askText = (p) => p.ask || (p.line ? 'Say it like you own it.' : 'What do you do?');

  function promptBlock(struck, hideAsk) {
    const g = S.st.game, p = S.st.prompt;
    const tag = el('div', { class: 'tag', text: 'Round ' + (g.round + 1) + ' of ' + Math.max(g.total, g.round + 1) + (p && p.value ? ' \u00B7 ' + p.value : '') });
    if (!p) return el('div', { class: 'prompt' }, [tag, el('p', { class: 'setup', text: 'There is no prompt for this round.' })]);
    return el('div', { class: 'prompt' }, [
      tag,
      p.setup ? el('p', { class: p.line ? 'setup' : 'situation', text: p.setup }) : null,
      p.line ? el('blockquote', { class: 'excuse' + (struck && isExcuse(p) ? ' struck' : '') }, el('span', { text: '\u201C' + p.line + '\u201D' })) : null,
      hideAsk ? null : el('p', { class: 'ask', text: askText(p) }),
    ]);
  }

  // Shown with the results: the value this round trains and the point to make about it.
  function valuePoint() {
    const p = S.st.prompt;
    if (!p || !(p.value || p.point)) return null;
    return el('div', { class: 'note' }, [
      el('div', { class: 'tag', text: 'The value' }),
      p.value ? el('h2', { text: p.value }) : null,
      p.point ? el('p', { text: p.point }) : null,
    ]);
  }

  function resultsList() {
    return listDyn(el('ol', { class: 'results' }), () => JSON.stringify(S.st.answers || []), () => {
      const all = S.st.answers || [], mineId = S.st.me ? S.st.me.answerId : null;
      const max = all.reduce((m, a) => Math.max(m, a.votes), 0);
      if (!all.length) return [el('li', { class: 'lede', text: 'Nobody answered this one.' })];
      // A projector can't scroll, so the big screen shows the top five. Phones show every answer.
      const rows = BIG ? all.slice(0, 5) : all;
      const more = all.length - rows.length;
      return rows.map((a) => {
        const bar = el('i'); bar.style.width = (max ? Math.round(a.votes / max * 100) : 0) + '%';
        return el('li', { class: 'result' + (max > 0 && a.votes === max ? ' win' : '') }, [
          el('div', { class: 'text' }, [
            el('span', { text: a.text }), a.id === mineId ? el('span', { class: 'pill', text: 'yours' }) : null,
            a.author ? el('div', { class: 'author' }, [avatar(a.author.avatar), el('span', { text: a.author.nick })]) : null,
          ]),
          el('div', { class: 'votes', text: String(a.votes) }),
          el('div', { class: 'bar' }, bar),
        ]);
      }).concat(more > 0 ? [el('li', { class: 'count', text: 'and ' + plural(more, 'more answer') + ' on your phone' })] : []);
    });
  }

  // Results on the left; the value and the standings beside them on a wide screen.
  function revealBody() {
    return el('div', { class: 'reveal' }, [resultsList(), el('div', { class: 'reveal-side' }, [valuePoint(), standings()])]);
  }

  const ordinal = (n) => n + (n % 100 >= 11 && n % 100 <= 13 ? 'th' : ['th', 'st', 'nd', 'rd'][n % 10] || 'th');

  // The race so far, shown under each round's results.
  function standings() {
    return listDyn(el('div', { class: 'standings' }), () => JSON.stringify([S.st.standings, S.st.myStanding]), () => {
      const rows = S.st.standings || [], mine = S.st.myStanding;
      if (!rows.length || !rows[0].score) return [];
      return [
        el('div', { class: 'tag', text: 'Standings' }),
        el('ol', { class: 'race' }, rows.map((p) => el('li', { class: p.me ? 'me' : null }, [
          el('span', { class: 'rank', text: String(p.rank) }),
          avatar(p.avatar),
          el('span', { class: 'nick', text: p.nick }),
          el('span', { class: 'score', text: String(p.score) }),
          p.move ? el('span', { class: 'move ' + (p.move > 0 ? 'up' : 'down'), text: (p.move > 0 ? '\u25B2' : '\u25BC') + Math.abs(p.move) }) : null,
        ]))),
        mine && mine.rank > rows[rows.length - 1].rank ? el('p', { class: 'count', text: 'You are ' + ordinal(mine.rank) + ' with ' + mine.score + '.' }) : null,
      ].filter(Boolean);
    });
  }

  function awards() {
    return listDyn(el('div', { class: 'awards after-podium' }), () => JSON.stringify(S.st.awards || []), () => (S.st.awards || []).map((a) => el('div', { class: 'award' }, [
      el('div', { class: 'tag', text: a.title }),
      el('div', { class: 'who' }, [avatar(a.avatar), el('span', { class: 'nick', text: a.nick })]),
      a.text ? el('p', { class: 'quote', text: '\u201C' + a.text + '\u201D' }) : null,
      el('p', { class: 'count', text: a.detail }),
    ])));
  }

  function board() {
    return listDyn(el('ol', { class: 'board after-podium' }), () => JSON.stringify(S.st.board || []), () => {
      const rows = S.st.board || [];
      if (!rows.length) return [el('li', { text: 'No players this game.' })];
      return rows.map((p) => el('li', { class: p.me ? 'me' : null }, [
        el('span', { class: 'nick' }, [avatar(p.avatar), el('span', { text: p.nick })]),
        el('span', { class: 'score', text: String(p.score) }),
      ]));
    });
  }

  // Top three on stepped blocks, revealed third place first. Tied players share a place.
  function podium() {
    return listDyn(el('div', { class: 'podium-wrap' }), () => JSON.stringify(S.st.board || []), () => {
      const rows = S.st.board || [], top = rows.length ? rows[0].score : 0;
      stage.classList.toggle('staged', top > 0);
      if (!top) return [el('h1', { text: 'Final scores' })];
      const stand = rows.slice(0, 3).map((p, i) => ({ p, slot: i + 1, place: 1 + rows.filter((q) => q.score > p.score).length }));
      const names = rows.filter((p) => p.score === top).map((p) => p.nick);
      const title = names.length === 1 ? names[0] + ' wins' : names.slice(0, -1).join(', ') + ' and ' + names[names.length - 1] + ' tie';
      return [
        el('div', { class: 'podium' }, [stand[1], stand[0], stand[2]].filter(Boolean).map((s) => el('div', { class: 'spot place-' + s.place + ' slot-' + s.slot }, [
          el('div', { class: 'who' }, [
            avatar(s.p.avatar, true),
            el('div', { class: 'name', text: s.p.nick }),
            el('div', { class: 'pts', text: plural(s.p.score / 100, 'vote') }),
          ]),
          el('div', { class: 'block' }, el('span', { text: String(s.place) })),
        ]))),
        el('h1', { class: 'after-podium', text: title }),
      ];
    });
  }

  function takeaways() {
    return el('div', { class: 'note after-podium' }, [
      el('h2', { text: 'Talk it over' }),
      el('ol', { class: 'takeaways' }, [
        el('li', { text: 'Which answer surprised you?' }),
        el('li', { text: 'Which round was hardest to answer honestly?' }),
        el('li', { text: "What is one thing you'll do differently this week?" }),
      ]),
    ]);
  }

  const INTRO = "Each round puts one of our values to work in a moment from the job. Write what you'd really say or do. Nobody sees who wrote what. Then everyone votes for the best answer.";

  function buildJoin() {
    const input = el('input', { type: 'text', id: 'nick', maxlength: MAX_NICK, autocomplete: 'off', placeholder: 'Any name you like', value: S.nick });
    return [
      el('h1', { text: 'What would you really do?' }),
      el('p', { class: 'lede', text: INTRO }),
      el('form', { class: 'field', onsubmit: (e) => { e.preventDefault(); join(input.value); } }, [
        el('label', { for: 'nick', text: 'Your name' }),
        input,
        AVATAR_KEYS.length ? el('div', { class: 'field' }, [
          el('div', { class: 'label', id: 'avatar-label', text: 'Pick an avatar' }),
          el('div', { class: 'avatars', role: 'radiogroup', 'aria-labelledby': 'avatar-label' }, AVATAR_KEYS.map((key) => el('button', {
            type: 'button', class: 'avatar-pick', role: 'radio', 'aria-label': key, 'aria-checked': key === S.avatar ? 'true' : 'false',
            onclick: (e) => {
              S.avatar = key;
              e.currentTarget.parentNode.querySelectorAll('.avatar-pick').forEach((b) => b.setAttribute('aria-checked', b === e.currentTarget ? 'true' : 'false'));
            },
          }, avatar(key)))),
        ]) : null,
        el('div', { class: 'row' }, el('button', { type: 'submit', text: 'Join the game' })),
      ]),
    ];
  }

  function buildHostKey() {
    const input = el('input', { type: 'password', id: 'host-key', autocomplete: 'off' });
    return [
      el('h1', { text: 'Host the game' }),
      el('p', { class: 'lede', text: 'Enter the host key to run the rounds from this screen. Players join from the main page and never need it.' }),
      el('form', { class: 'field', onsubmit: (e) => { e.preventDefault(); tryHostKey(input.value); } }, [
        el('label', { for: 'host-key', text: 'Host key' }),
        input,
        el('div', { class: 'row' }, el('button', { type: 'submit', text: 'Open host view' })),
      ]),
    ];
  }

  function buildPlayer() {
    const st = S.st, ph = st.game.phase, r = st.game.round;
    if (ph === 'lobby') return [
      avatar(st.me.avatar, true),
      el('h1', { text: "You're in, " + st.me.nick + '.' }),
      el('p', { class: 'lede', text: 'Round 1 starts when the host is ready. Watch the big screen.' }),
      textDyn(el('p', { class: 'count' }), () => plural(S.st.players, 'player') + ' joined'),
    ];
    if (ph === 'write') {
      if (st.me.answer && !S.editAns) return [
        promptBlock(false),
        el('div', { class: 'note' }, [el('div', { class: 'tag', text: 'Locked in' }), textDyn(el('p'), () => (S.st.me && S.st.me.answer) || '')]),
        el('div', { class: 'row' }, [
          el('button', { class: 'quiet', type: 'button', text: 'Change my answer', onclick: () => { S.editAns = true; render(); } }),
          textDyn(el('span', { class: 'count' }), () => plural(S.st.answerCount, 'answer') + ' in'),
          timerNode(),
        ]),
      ];
      const ta = el('textarea', { id: 'answer-r' + r, maxlength: MAX_ANSWER, placeholder: 'Your answer', value: st.me.answer || '' });
      const counter = el('span', { class: 'count', text: ta.value.length + ' / ' + MAX_ANSWER });
      ta.addEventListener('input', () => { counter.textContent = ta.value.length + ' / ' + MAX_ANSWER; });
      return [
        promptBlock(false, true),
        el('form', { class: 'field', onsubmit: (e) => { e.preventDefault(); submitAnswer(ta.value, e.target.querySelector('button')); } }, [
          el('label', { for: 'answer-r' + r, text: st.prompt ? askText(st.prompt) : 'Your answer' }),
          ta,
          el('div', { class: 'row' }, [el('button', { type: 'submit', text: 'Submit' }), counter, timerNode()]),
        ]),
      ];
    }
    if (ph === 'vote') return [
      promptBlock(false),
      el('div', { class: 'row' }, [el('p', { text: "Pick the best answer. You can't pick your own." }), timerNode()]),
      listDyn(el('ul', { class: 'answers' }), () => JSON.stringify([S.st.answers, S.st.me && S.st.me.vote, S.st.me && S.st.me.answerId]), () => {
        const list = S.st.answers || [], me = S.st.me || {};
        if (!list.length) return [el('li', { class: 'lede', text: 'No answers came in this round.' })];
        return list.map((a) => {
          const own = a.id === me.answerId;
          return el('li', null, el('button', {
            class: 'answer', type: 'button', disabled: own, 'aria-pressed': a.id === me.vote ? 'true' : 'false',
            onclick: () => vote(a.id),
          }, [el('span', { text: a.text }), own ? el('span', { class: 'pill mine', text: 'yours' }) : null]));
        });
      }),
    ];
    if (ph === 'reveal') return [promptBlock(true), revealBody()];
    return [
      podium(), awards(), board(), takeaways(),
    ];
  }

  function joinCode() {
    const box = el('div', { class: 'qr' });
    if (window.qrcode) {
      const qr = window.qrcode(0, 'M');
      qr.addData(window.location.origin + '/');
      qr.make();
      box.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
    } else box.hidden = true;
    return box;
  }

  // The big-screen views, shared by the host and the projector.
  function buildBig() {
    const st = S.st, ph = st.game.phase;
    if (ph === 'lobby') {
      const prompts = st.prompts || [];
      if (HOST && S.editing) {
        const ta = el('textarea', { id: 'prompts-edit', class: 'wide', spellcheck: 'true', value: prompts.map((p) => [p.setup, p.line, p.ask, p.value, p.point].join(' :: ').replace(/(\s*::\s*)+$/, '')).join('\n') });
        return [
          el('h2', { text: 'Edit prompts' }),
          el('p', { class: 'lede', text: 'One round per line, in the order they will be played: situation :: quote :: question :: value :: point to make at the reveal. Leave a part empty to skip it, like: You find a mistake nobody noticed. :: :: What do you do? :: Total Effort' }),
          el('div', { class: 'field' }, [el('label', { for: 'prompts-edit', text: 'Prompts' }), ta]),
          el('div', { class: 'row' }, [
            el('button', { type: 'button', text: 'Save prompts', onclick: () => savePrompts(ta.value) }),
            el('button', { class: 'quiet', type: 'button', text: 'Cancel', onclick: () => { S.editing = false; render(); } }),
          ]),
        ];
      }
      return [
        el('div', { class: 'join' }, [
          joinCode(),
          el('div', { class: 'join-text' }, [
            el('div', { class: 'tag', text: 'Join on your phone' }),
            el('div', { class: 'join-url', text: window.location.host }),
            el('p', { class: 'lede', text: 'Scan the code or type the address, then pick a name and an avatar.' }),
          ]),
        ]),
        el('h1', { text: 'What would you really do?' }),
        el('p', { class: 'lede', text: INTRO }),
        textDyn(el('div', { class: 'big-count' }), () => plural(S.st.players, 'player')),
        listDyn(el('div', { class: 'chips' }), () => JSON.stringify(S.st.roster || []), () => (S.st.roster || []).map((p) => el('span', { class: 'chip' }, [avatar(p.avatar), el('span', { text: p.nick })]))),
        // Prompts stay off this screen so a mirrored projector doesn't spoil them.
        HOST ? textDyn(el('p', { class: 'count' }), () => plural(S.st.game.total, 'prompt') + ' loaded. To run the game from another device, put ' + window.location.host + '/screen on the projector.') : null,
      ];
    }
    if (ph === 'write') return [
      promptBlock(false),
      el('div', { class: 'row' }, [
        textDyn(el('div', { class: 'big-count' }), () => S.st.answerCount + ' / ' + S.st.players),
        el('span', { text: 'answers in' }), timerNode(),
      ]),
    ];
    if (ph === 'vote') return [
      promptBlock(false),
      el('div', { class: 'row' }, [
        textDyn(el('span', { class: 'count' }), () => S.st.voteCount + ' of ' + S.st.players + ' votes in'),
        timerNode(),
      ]),
      listDyn(el('ul', { class: 'answers' }), () => JSON.stringify(S.st.answers || []), () => {
        const list = S.st.answers || [];
        if (!list.length) return [el('li', { class: 'lede', text: 'No answers came in this round.' })];
        return list.map((a) => el('li', null, el('div', { class: 'answer' }, [
          el('span', { text: a.text }),
          HOST ? el('button', { class: 'quiet small mine', type: 'button', text: 'Remove', onclick: () => removeAnswer(a.id) }) : null,
        ])));
      }),
    ];
    if (ph === 'reveal') return [promptBlock(true), revealBody()];
    // No full scoreboard here: a projector can't scroll, and every phone already shows it.
    return [podium(), awards(), takeaways()];
  }

  function hostButtons() {
    const g = S.st.game, out = [];
    const b = (text, fn, quiet, disabled) => el('button', { type: 'button', class: quiet ? 'quiet' : null, text, onclick: fn, disabled });
    if (g.phase === 'lobby' && !S.editing) {
      out.push(b('Start round 1', () => advance('start'), false, g.total === 0));
      out.push(b('Edit prompts', () => { S.editing = true; render(); }, true));
    }
    if (g.phase === 'write') out.push(b('Close answers and vote', () => advance('vote')));
    if (g.phase === 'vote') out.push(b('Reveal results', () => advance('reveal')));
    if (g.phase === 'reveal') {
      if (g.round + 1 >= g.total) out.push(b('Show final scores', () => advance('end')));
      else {
        out.push(b('Next prompt', () => advance('next')));
        out.push(b('End game now', () => advance('end'), true));
      }
    }
    if (g.phase === 'end') out.push(b('New game', () => advance('new')));
    out.push(el('span', { class: 'spacer' }));
    if (g.phase !== 'lobby' && g.phase !== 'end') {
      if (S.confirmRestart) {
        out.push(b('Yes, restart', () => { S.confirmRestart = false; advance('new'); }));
        out.push(b('Keep playing', () => { S.confirmRestart = false; render(); }, true));
      } else out.push(b('Restart', () => { S.confirmRestart = true; render(); }, true));
    }
    return out;
  }

  function viewKey() {
    const st = S.st;
    if (!st) return S.offline ? 'offline' : 'connecting';
    const g = st.game, base = g.id + ':' + g.phase + ':' + g.round;
    if (SCREEN) return 'screen:' + base;
    if (HOST) return !st.isHost ? 'hostkey' : 'host:' + base + (g.phase === 'lobby' ? (S.editing ? ':edit' : ':view') : '');
    if (!st.me) return 'join';
    return 'player:' + base + (g.phase === 'write' ? (st.me.answer && !S.editAns ? ':done' : ':form') : '');
  }

  let lastKey = null, lastBarKey = null;
  function render() {
    const st = S.st, k = viewKey(), hosting = HOST && st && st.isHost;
    document.body.classList.toggle('host', !!hosting || (SCREEN && !!st));
    if (k !== lastKey) {
      lastKey = k; dyn = []; stage.classList.remove('staged');
      const kids = !st
        ? [el('p', { class: 'lede', text: S.offline ? "Can't reach the game. Check your connection." : 'Connecting to the game…' })]
        : SCREEN ? buildBig()
        : HOST ? (st.isHost ? buildBig() : buildHostKey())
        : !st.me ? buildJoin() : buildPlayer();
      stage.replaceChildren(...kids.filter(Boolean));
    }
    dyn.forEach((f) => f());
    tickTimers();

    hostbar.hidden = !hosting;
    if (hosting) {
      const bk = [st.game.id, st.game.phase, st.game.round, st.game.total, S.editing, S.confirmRestart].join(':');
      if (bk !== lastBarKey) { lastBarKey = bk; hostbarInner.replaceChildren(...hostButtons()); }
    }
    let meta = '';
    if (st) {
      const g = st.game;
      meta = (g.phase === 'lobby' ? 'Lobby' : g.phase === 'end' ? 'Game over' : 'Round ' + (g.round + 1) + ' of ' + Math.max(g.total, g.round + 1))
        + ' · ' + plural(st.players, 'player') + (S.offline ? ' · reconnecting…' : '');
    }
    if (metaEl.textContent !== meta) metaEl.textContent = meta;
  }

  setInterval(tickTimers, 500);
  render();
  poll();
})();
