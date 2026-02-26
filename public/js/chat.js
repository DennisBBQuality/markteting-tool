// ========== Chat Module ==========
let chatState = {
  activeChannel: null,
  activeThread: null,
  channels: [],
  threads: [],
  messages: [],
  pollInterval: null,
  lastPollTime: null,
};

async function renderChat() {
  const container = document.getElementById('view-chat');

  container.innerHTML = `
    <div class="chat-layout">
      <!-- Left sidebar: channels & DMs -->
      <div class="chat-sidebar">
        <div class="chat-sidebar-header">
          <h3>Berichten</h3>
        </div>

        <!-- Channels section -->
        <div class="chat-section">
          <div class="chat-section-header">
            <span><i class="fas fa-hashtag"></i> Kanalen</span>
            ${App.currentUser.rol !== 'lid' ? `<button class="btn-icon" onclick="openChannelModal()" title="Nieuw kanaal"><i class="fas fa-plus"></i></button>` : ''}
          </div>
          <div id="chat-channels-list" class="chat-list"></div>
        </div>

        <!-- Project channels -->
        <div class="chat-section">
          <div class="chat-section-header">
            <span><i class="fas fa-folder"></i> Projecten</span>
          </div>
          <div id="chat-project-channels-list" class="chat-list"></div>
        </div>

        <!-- Direct messages -->
        <div class="chat-section">
          <div class="chat-section-header">
            <span><i class="fas fa-user"></i> Direct</span>
            <button class="btn-icon" onclick="openNewDMModal()" title="Nieuw gesprek"><i class="fas fa-plus"></i></button>
          </div>
          <div id="chat-dm-list" class="chat-list"></div>
        </div>
      </div>

      <!-- Main chat area -->
      <div class="chat-main">
        <div class="chat-header" id="chat-header">
          <span class="chat-header-title">Selecteer een gesprek</span>
        </div>
        <div class="chat-messages" id="chat-messages">
          <div class="chat-empty">
            <i class="fas fa-comments"></i>
            <p>Selecteer een kanaal of persoon om te beginnen</p>
          </div>
        </div>
        <div class="chat-input-container" id="chat-input-container" style="display:none;">
          <div class="chat-mentions-popup hidden" id="mentions-popup"></div>
          <div class="chat-input-wrapper">
            <textarea id="chat-input" placeholder="Typ een bericht..." rows="1"
              onkeydown="handleChatKeydown(event)"
              oninput="handleChatInput(event)"></textarea>
            <button type="button" class="btn-icon emoji-trigger" onclick="toggleEmojiPicker('chat-emoji-picker')" title="Emoji invoegen">
              <i class="far fa-smile"></i>
            </button>
            ${createEmojiPicker('chat-input', 'chat-emoji-picker')}
          </div>
          <button class="btn btn-primary" onclick="sendMessage()">
            <i class="fas fa-paper-plane"></i>
          </button>
        </div>
      </div>
    </div>
  `;

  await loadChatData();
  startPolling();
}

async function loadChatData() {
  const [channels, threads] = await Promise.all([
    api('/api/chat/channels'),
    api('/api/chat/direct'),
  ]);

  if (channels) {
    chatState.channels = channels;
    renderChannelsList();
  }
  if (threads) {
    chatState.threads = threads;
    renderDMList();
  }
}

function renderChannelsList() {
  const generalChannels = chatState.channels.filter(c => c.type === 'general');
  const projectChannels = chatState.channels.filter(c => c.type === 'project');

  document.getElementById('chat-channels-list').innerHTML = generalChannels.map(c => `
    <div class="chat-list-item ${chatState.activeChannel?.id === c.id ? 'active' : ''}"
         onclick="selectChannel('${c.id}')">
      <span class="chat-list-icon">#</span>
      <span class="chat-list-name">${escHtml(c.naam)}</span>
    </div>
  `).join('') || '<div class="chat-empty-small">Geen kanalen</div>';

  document.getElementById('chat-project-channels-list').innerHTML = projectChannels.map(c => `
    <div class="chat-list-item ${chatState.activeChannel?.id === c.id ? 'active' : ''}"
         onclick="selectChannel('${c.id}')"
         style="border-left: 3px solid ${c.project_kleur || '#3B82F6'}">
      <span class="chat-list-name">${escHtml(c.project_naam || c.naam)}</span>
    </div>
  `).join('') || '<div class="chat-empty-small">Geen project kanalen</div>';
}

function renderDMList() {
  const list = document.getElementById('chat-dm-list');
  if (!list) return;

  list.innerHTML = chatState.threads.map(t => `
    <div class="chat-list-item ${chatState.activeThread?.id === t.id ? 'active' : ''}"
         onclick="selectThread('${t.id}')">
      <div class="chat-dm-avatar" style="background:${t.andere_kleur || '#3B82F6'}">
        ${(t.andere_naam || '?').charAt(0).toUpperCase()}
      </div>
      <div class="chat-dm-info">
        <span class="chat-dm-name">${escHtml(t.andere_naam || 'Onbekend')}</span>
        <span class="chat-dm-preview">${escHtml((t.laatste_bericht || '').substring(0, 25))}${(t.laatste_bericht || '').length > 25 ? '...' : ''}</span>
      </div>
    </div>
  `).join('') || '<div class="chat-empty-small">Geen gesprekken</div>';
}

async function selectChannel(id) {
  chatState.activeChannel = chatState.channels.find(c => c.id === id);
  chatState.activeThread = null;

  const header = document.getElementById('chat-header');
  header.innerHTML = `
    <span class="chat-header-icon">#</span>
    <span class="chat-header-title">${escHtml(chatState.activeChannel.naam)}</span>
    ${chatState.activeChannel.beschrijving ? `<span class="chat-header-desc">${escHtml(chatState.activeChannel.beschrijving)}</span>` : ''}
  `;

  document.getElementById('chat-input-container').style.display = 'flex';
  await loadMessages();

  // Mark notifications for this channel as read
  await api(`/api/notifications/read-channel/${id}`, { method: 'PUT' });
  fetchUnreadCount();

  renderChannelsList();
  renderDMList();
}

async function selectThread(id) {
  chatState.activeThread = chatState.threads.find(t => t.id === id);
  chatState.activeChannel = null;

  const header = document.getElementById('chat-header');
  header.innerHTML = `
    <div class="chat-dm-avatar" style="background:${chatState.activeThread.andere_kleur || '#3B82F6'}">
      ${(chatState.activeThread.andere_naam || '?').charAt(0).toUpperCase()}
    </div>
    <span class="chat-header-title">${escHtml(chatState.activeThread.andere_naam || 'Onbekend')}</span>
  `;

  document.getElementById('chat-input-container').style.display = 'flex';
  await loadMessages();

  // Mark notifications for this thread as read
  await api(`/api/notifications/read-thread/${id}`, { method: 'PUT' });
  fetchUnreadCount();

  renderChannelsList();
  renderDMList();
}

async function loadMessages() {
  const container = document.getElementById('chat-messages');
  container.innerHTML = '<div class="chat-loading"><i class="fas fa-spinner fa-spin"></i> Laden...</div>';

  let messages;
  if (chatState.activeChannel) {
    messages = await api(`/api/chat/channels/${chatState.activeChannel.id}/messages`);
  } else if (chatState.activeThread) {
    messages = await api(`/api/chat/threads/${chatState.activeThread.id}/messages`);
  }

  if (!messages) return;
  chatState.messages = messages;
  renderMessages();
}

function renderMessages() {
  const container = document.getElementById('chat-messages');

  if (chatState.messages.length === 0) {
    container.innerHTML = `
      <div class="chat-empty">
        <i class="fas fa-comments"></i>
        <p>Nog geen berichten. Begin het gesprek!</p>
      </div>`;
    return;
  }

  let html = '';
  let lastDate = null;
  let lastSender = null;

  for (const m of chatState.messages) {
    const messageDate = m.created_at.split('T')[0];
    if (messageDate !== lastDate) {
      html += `<div class="chat-date-divider"><span>${formatDateLabel(m.created_at)}</span></div>`;
      lastDate = messageDate;
      lastSender = null;
    }

    const isOwn = m.afzender_id === App.currentUser.id;
    const showAvatar = m.afzender_id !== lastSender;

    html += `
      <div class="chat-message ${isOwn ? 'own' : ''} ${showAvatar ? '' : 'continued'}">
        ${showAvatar ? `
          <div class="chat-message-avatar" style="background:${m.afzender_kleur || '#3B82F6'}">
            ${(m.afzender_naam || '?').charAt(0).toUpperCase()}
          </div>
        ` : '<div class="chat-message-avatar-placeholder"></div>'}
        <div class="chat-message-content">
          ${showAvatar ? `
            <div class="chat-message-header">
              <span class="chat-message-sender">${escHtml(m.afzender_naam || 'Onbekend')}</span>
              <span class="chat-message-time">${formatTime(m.created_at)}</span>
            </div>
          ` : ''}
          <div class="chat-message-text">${formatMessageContent(m.inhoud)}</div>
        </div>
      </div>`;

    lastSender = m.afzender_id;
  }

  container.innerHTML = html;
  container.scrollTop = container.scrollHeight;
}

function formatMessageContent(text) {
  if (!text) return '';
  // Escape HTML first
  let safe = escHtml(text);
  // Convert @mentions to styled spans
  safe = safe.replace(/@(\w+)/g, '<span class="chat-mention">@$1</span>');
  // Convert URLs to links
  safe = safe.replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" rel="noopener">$1</a>');
  // Convert newlines
  safe = safe.replace(/\n/g, '<br>');
  return safe;
}

function formatTime(str) {
  if (!str) return '';
  return new Date(str).toLocaleTimeString('nl-NL', { hour: '2-digit', minute: '2-digit' });
}

function formatDateLabel(str) {
  if (!str) return '';
  const date = new Date(str);
  const today = new Date();
  const yesterday = new Date(today);
  yesterday.setDate(yesterday.getDate() - 1);

  if (date.toDateString() === today.toDateString()) return 'Vandaag';
  if (date.toDateString() === yesterday.toDateString()) return 'Gisteren';
  return date.toLocaleDateString('nl-NL', { weekday: 'long', day: 'numeric', month: 'long' });
}

async function sendMessage() {
  const input = document.getElementById('chat-input');
  const content = input.value.trim();
  if (!content) return;

  input.value = '';
  input.style.height = 'auto';
  hideMentionsPopup();

  let endpoint;
  if (chatState.activeChannel) {
    endpoint = `/api/chat/channels/${chatState.activeChannel.id}/messages`;
  } else if (chatState.activeThread) {
    endpoint = `/api/chat/threads/${chatState.activeThread.id}/messages`;
  } else {
    return;
  }

  const result = await api(endpoint, { method: 'POST', body: { inhoud: content } });
  if (result) {
    chatState.messages.push(result);
    renderMessages();
  }
}

function handleChatKeydown(e) {
  const popup = document.getElementById('mentions-popup');
  const isPopupVisible = popup && !popup.classList.contains('hidden');

  if (e.key === 'Enter' && !e.shiftKey) {
    if (isPopupVisible) {
      // Select first mention
      const firstItem = popup.querySelector('.mention-item');
      if (firstItem) firstItem.click();
    } else {
      e.preventDefault();
      sendMessage();
    }
  } else if (e.key === 'Escape' && isPopupVisible) {
    hideMentionsPopup();
  }
}

function handleChatInput(e) {
  // Auto-resize textarea
  e.target.style.height = 'auto';
  e.target.style.height = Math.min(e.target.scrollHeight, 120) + 'px';

  // Show mentions popup
  const text = e.target.value;
  const cursorPos = e.target.selectionStart;
  const textBeforeCursor = text.substring(0, cursorPos);
  const mentionMatch = textBeforeCursor.match(/@(\w*)$/);

  if (mentionMatch) {
    showMentionsPopup(mentionMatch[1]);
  } else {
    hideMentionsPopup();
  }
}

function showMentionsPopup(query) {
  const filtered = App.users.filter(u =>
    u.actief && u.naam.toLowerCase().includes(query.toLowerCase()) && u.id !== App.currentUser.id
  ).slice(0, 5);

  const popup = document.getElementById('mentions-popup');
  if (!popup) return;

  if (filtered.length === 0) {
    popup.classList.add('hidden');
    return;
  }

  popup.innerHTML = filtered.map(u => `
    <div class="mention-item" onclick="insertMention('${escHtml(u.naam)}')">
      <div class="mention-avatar" style="background:${u.kleur || '#3B82F6'}">${u.naam.charAt(0).toUpperCase()}</div>
      <span>${escHtml(u.naam)}</span>
    </div>
  `).join('');
  popup.classList.remove('hidden');
}

function hideMentionsPopup() {
  const popup = document.getElementById('mentions-popup');
  if (popup) popup.classList.add('hidden');
}

function insertMention(name) {
  const input = document.getElementById('chat-input');
  const text = input.value;
  const cursorPos = input.selectionStart;
  const textBeforeCursor = text.substring(0, cursorPos);
  const textAfterCursor = text.substring(cursorPos);

  // Find the @ symbol position
  const atIndex = textBeforeCursor.lastIndexOf('@');
  const newText = textBeforeCursor.substring(0, atIndex) + '@' + name + ' ' + textAfterCursor;

  input.value = newText;
  input.focus();
  const newCursorPos = atIndex + name.length + 2;
  input.setSelectionRange(newCursorPos, newCursorPos);
  hideMentionsPopup();
}

// ========== Polling ==========
function startPolling() {
  if (chatState.pollInterval) clearInterval(chatState.pollInterval);
  chatState.lastPollTime = new Date().toISOString();

  chatState.pollInterval = setInterval(async () => {
    if (App.currentView !== 'chat') return;

    const result = await api(`/api/chat/poll?since=${encodeURIComponent(chatState.lastPollTime)}`);
    if (result) {
      chatState.lastPollTime = result.timestamp;

      // Update unread count in sidebar
      updateNotificationBadge(result.unreadCount);

      // Add new channel messages if in active channel
      if (chatState.activeChannel && result.channelMessages) {
        for (const msg of result.channelMessages) {
          if (msg.channel_id === chatState.activeChannel.id) {
            if (!chatState.messages.find(m => m.id === msg.id)) {
              chatState.messages.push(msg);
            }
          }
        }
      }

      // Add new DM messages if in active thread
      if (chatState.activeThread && result.dmMessages) {
        for (const msg of result.dmMessages) {
          if (msg.thread_id === chatState.activeThread.id) {
            if (!chatState.messages.find(m => m.id === msg.id)) {
              chatState.messages.push(msg);
            }
          }
        }
      }

      // Re-render if new messages
      const hasNew = (result.channelMessages?.length > 0 || result.dmMessages?.length > 0);
      if (hasNew) {
        renderMessages();
        // Also refresh sidebar lists for latest message preview
        loadChatData();
      }
    }
  }, 5000); // Poll every 5 seconds
}

function stopPolling() {
  if (chatState.pollInterval) {
    clearInterval(chatState.pollInterval);
    chatState.pollInterval = null;
  }
}

// ========== Notification Badge ==========
function updateNotificationBadge(count) {
  const badge = document.getElementById('chat-notification-badge');
  if (badge) {
    badge.textContent = count > 99 ? '99+' : count;
    badge.style.display = count > 0 ? 'flex' : 'none';
  }
}

// Global function to fetch unread count (called from app.js)
async function fetchUnreadCount() {
  const result = await api('/api/notifications/unread-count');
  if (result) {
    updateNotificationBadge(result.count);
  }
}

// ========== DM Modal ==========
function openNewDMModal() {
  const availableUsers = App.users.filter(u => u.id !== App.currentUser.id && u.actief);

  openModal('Nieuw gesprek', `
    <div class="form-group">
      <label>Persoon</label>
      <select id="dm-user" class="form-control">
        <option value="">Selecteer een persoon...</option>
        ${availableUsers.map(u => `<option value="${u.id}">${escHtml(u.naam)}</option>`).join('')}
      </select>
    </div>
  `, `
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="startNewDM()">Start gesprek</button>
  `);
}

async function startNewDM() {
  const userId = document.getElementById('dm-user').value;
  if (!userId) {
    toast('Selecteer een persoon', 'error');
    return;
  }

  const thread = await api(`/api/chat/direct/${userId}`);
  if (thread) {
    closeModal();
    // Add to threads if not exists
    if (!chatState.threads.find(t => t.id === thread.id)) {
      chatState.threads.unshift(thread);
    }
    await selectThread(thread.id);
  }
}

// ========== Channel Modal ==========
function openChannelModal() {
  openModal('Nieuw kanaal', `
    <div class="form-group">
      <label>Naam</label>
      <input type="text" id="channel-naam" class="form-control" placeholder="bijv. marketing-algemeen">
    </div>
    <div class="form-group">
      <label>Beschrijving (optioneel)</label>
      <textarea id="channel-beschrijving" class="form-control" rows="2" placeholder="Waar gaat dit kanaal over?"></textarea>
    </div>
  `, `
    <button class="btn btn-outline" onclick="closeModal()">Annuleren</button>
    <button class="btn btn-primary" onclick="saveChannel()">Aanmaken</button>
  `);
}

async function saveChannel() {
  const naam = document.getElementById('channel-naam').value.trim();
  if (!naam) {
    toast('Voer een naam in', 'error');
    return;
  }

  const result = await api('/api/chat/channels', {
    method: 'POST',
    body: {
      naam,
      beschrijving: document.getElementById('channel-beschrijving').value.trim()
    }
  });

  if (result) {
    closeModal();
    toast('Kanaal aangemaakt', 'success');
    chatState.channels.push(result);
    renderChannelsList();
    selectChannel(result.id);
  }
}

// ========== Cleanup on view change ==========
function cleanupChat() {
  stopPolling();
  chatState.activeChannel = null;
  chatState.activeThread = null;
  chatState.messages = [];
}
