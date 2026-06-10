// assets/script.js

document.addEventListener('DOMContentLoaded', function() {
    const appContainer = document.querySelector('.app-container');
    const messageForm = document.getElementById('messageForm');
    const messageArea = document.getElementById('messageArea');
    const messageTextarea = document.getElementById('message');
    const contactSearchInput = document.getElementById('contactSearch');
    const contactList = document.getElementById('contactList');
    const backBtn = document.getElementById('backBtn');
    const urlParams = new URLSearchParams(window.location.search);
    
    let selectedPhone = urlParams.get('phone') || '';

    // Escape HTML helper
    const escapeHTML = str => str.replace(/[&<>'"]/g, 
        tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
    );

    // Auto-scroll chat message container to the bottom
    if (messageArea) {
        messageArea.scrollTop = messageArea.scrollHeight;
    }

    // Auto-resize composer textarea
    if (messageTextarea) {
        messageTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            const newHeight = Math.min(this.scrollHeight, 120); // max height 120px
            this.style.height = (newHeight - 10) + 'px'; // adjust for padding
        });
    }

    // Form submission state improvements & AJAX posting (prevent page refresh)
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
            e.preventDefault(); // Intercept default reload submit
            
            const phoneInput = document.getElementById('phone_number');
            const submitBtn = document.getElementById('submitBtn');
            const messageVal = messageTextarea.value.trim();
            const phoneVal = phoneInput ? phoneInput.value.trim() : '';
            const csrfToken = messageForm.querySelector('input[name="csrf_token"]').value;
            
            if (!phoneVal) {
                alert('Please enter a phone number.');
                if (phoneInput) phoneInput.focus();
                return;
            }
            if (!messageVal) {
                alert('Please enter a message.');
                messageTextarea.focus();
                return;
            }
            
            // Disable button and visual feedback
            submitBtn.disabled = true;
            submitBtn.style.opacity = '0.5';
            
            // Determine POST url
            const targetUrl = messageForm.getAttribute('action') || 'index.php';
            
            // Generate current time string
            const now = new Date();
            const timeStr = now.getHours().toString().padStart(2, '0') + ':' + now.getMinutes().toString().padStart(2, '0');
            
            // Remove empty screen text if present
            const emptyPlaceholder = document.querySelector('.conversation-empty');
            if (emptyPlaceholder) {
                emptyPlaceholder.remove();
            }
            
            // Create temporary outgoing bubble
            const messageRow = document.createElement('div');
            messageRow.className = 'message-row outgoing temp-message';
            messageRow.innerHTML = `
                <div class="message-bubble">
                    <div class="message-text">${escapeHTML(messageVal).replace(/\n/g, '<br />')}</div>
                    <div class="message-footer">
                        <span class="message-time">${timeStr}</span>
                        <span class="status-tick status-tick-pending" title="pending">✓</span>
                    </div>
                </div>
            `;
            
            if (messageArea) {
                messageArea.appendChild(messageRow);
                messageArea.scrollTop = messageArea.scrollHeight;
            }
            
            // Clear composer input immediately for fluid UX
            messageTextarea.value = '';
            messageTextarea.style.height = 'auto';
            
            // Setup FormData
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('phone_number', phoneVal);
            formData.append('message', messageVal);
            
            // Send request via Fetch API (AJAX)
            fetch(targetUrl, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                
                if (data.success) {
                    // Update status tick on the bubble
                    const tickSpan = messageRow.querySelector('.status-tick');
                    if (tickSpan) {
                        tickSpan.className = `status-tick status-tick-${data.status}`;
                        tickSpan.title = data.status;
                        tickSpan.innerHTML = data.status === 'failed' ? '⚠️' : '✓✓';
                    }
                    
                    // If this was a new chat starting, redirect to its proper URL
                    if (selectedPhone === 'new') {
                        window.location.href = `index.php?phone=${encodeURIComponent(data.phone_number)}`;
                    } else {
                        // Immediately poll to fetch the updated lists
                        pollForUpdates();
                    }
                } else {
                    // Show error tick on message
                    const tickSpan = messageRow.querySelector('.status-tick');
                    if (tickSpan) {
                        tickSpan.className = 'status-tick status-tick-failed';
                        tickSpan.title = 'failed';
                        tickSpan.innerHTML = '⚠️';
                    }
                    alert('Error: ' + data.message);
                }
            })
            .catch(err => {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '1';
                const tickSpan = messageRow.querySelector('.status-tick');
                if (tickSpan) {
                    tickSpan.className = 'status-tick status-tick-failed';
                    tickSpan.title = 'failed';
                    tickSpan.innerHTML = '⚠️';
                }
                console.error(err);
                alert('An error occurred while sending the message.');
            });
        });

        // Submit on enter (Shift + Enter for new lines)
        messageTextarea.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                messageForm.dispatchEvent(new Event('submit'));
            }
        });
    }

    // Client-side Contact Searching
    if (contactSearchInput && contactList) {
        contactSearchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const contacts = contactList.querySelectorAll('.contact-item');
            
            contacts.forEach(function(contact) {
                const phone = contact.getAttribute('data-phone').toLowerCase();
                if (phone.includes(query)) {
                    contact.style.display = 'flex';
                } else {
                    contact.style.display = 'none';
                }
            });
        });
    }

    // Mobile Navigation: If a contact is selected, toggle the active view
    const hasPhone = urlParams.has('phone');
    if (hasPhone && appContainer) {
        appContainer.classList.add('chat-active');
    }

    if (backBtn && appContainer) {
        backBtn.addEventListener('click', function() {
            appContainer.classList.remove('chat-active');
            selectedPhone = '';
            // Update URL to remove phone selection
            history.pushState(null, '', 'index.php');
        });
    }

    // Dynamic rendering of message bubbles from JSON
    function renderMessages(messages) {
        if (!messageArea) return;
        
        if (messages.length === 0) {
            messageArea.innerHTML = `<div class="conversation-empty"><p>No messages yet. Send a message to start the conversation.</p></div>`;
            return;
        }

        let html = '';
        messages.forEach(msg => {
            if (msg.sent_message) {
                let tick = '✓';
                if (msg.status === 'failed') tick = '⚠️';
                else if (msg.status === 'sent' || msg.status === 'replied') tick = '✓✓';
                
                const sentTime = msg.created_at ? msg.created_at.split(' ')[1].substring(0, 5) : '';
                
                html += `
                    <div class="message-row outgoing">
                        <div class="message-bubble">
                            <div class="message-text">${escapeHTML(msg.sent_message).replace(/\n/g, '<br />')}</div>
                            <div class="message-footer">
                                <span class="message-time">${sentTime}</span>
                                <span class="status-tick status-tick-${msg.status}" title="${msg.status}">${tick}</span>
                            </div>
                        </div>
                    </div>
                `;
            }
            if (msg.reply_message) {
                const replyTime = msg.reply_received_at ? msg.reply_received_at.split(' ')[1].substring(0, 5) : '';
                html += `
                    <div class="message-row incoming">
                        <div class="message-bubble">
                            <div class="message-text">${escapeHTML(msg.reply_message).replace(/\n/g, '<br />')}</div>
                            <div class="message-footer">
                                <span class="message-time">${replyTime}</span>
                            </div>
                        </div>
                    </div>
                `;
            }
        });

        // Determine if user is scrolled near bottom
        const isNearBottom = messageArea.scrollHeight - messageArea.scrollTop - messageArea.clientHeight < 120;
        
        messageArea.innerHTML = html;
        
        if (isNearBottom) {
            messageArea.scrollTop = messageArea.scrollHeight;
        }
    }

    // Dynamic rendering of contacts from JSON
    function renderContacts(contactsData) {
        if (!contactList) return;

        const searchQuery = contactSearchInput ? contactSearchInput.value.toLowerCase().trim() : '';

        if (contactsData.length === 0) {
            contactList.innerHTML = `<div class="no-contacts">No conversations yet</div>`;
            return;
        }

        let html = '';
        contactsData.forEach(contact => {
            const phone = contact.phone_number;
            const isActive = (phone === selectedPhone);
            
            let lastPreview = contact.reply_message ? contact.reply_message : contact.sent_message;
            if (lastPreview.length > 40) {
                lastPreview = lastPreview.substring(0, 40) + '...';
            }

            const displayStyle = (searchQuery === '' || phone.toLowerCase().includes(searchQuery)) ? 'flex' : 'none';

            html += `
                <a href="index.php?phone=${encodeURIComponent(phone)}" class="contact-item ${isActive ? 'active' : ''}" data-phone="${escapeHTML(phone)}" style="display: ${displayStyle}">
                    <div class="contact-avatar">
                        ${escapeHTML(phone.substring(phone.length - 2))}
                    </div>
                    <div class="contact-info">
                        <div class="contact-meta">
                            <span class="contact-phone">${escapeHTML(phone)}</span>
                            <span class="contact-time">${escapeHTML(contact.time_formatted)}</span>
                        </div>
                        <div class="contact-preview-row">
                            <span class="contact-preview">${escapeHTML(lastPreview)}</span>
                            <span class="status-dot status-dot-${escapeHTML(contact.status)}" title="${escapeHTML(contact.status)}"></span>
                        </div>
                    </div>
                </a>
            `;
        });

        contactList.innerHTML = html;
    }

    // Polling function for real-time updates
    function pollForUpdates() {
        // If we are actively editing a new chat target, don't poll active chat to overwrite it
        if (selectedPhone === 'new') return;

        let pollUrl = 'index.php?action=poll&t=' + new Date().getTime();
        if (selectedPhone) {
            pollUrl += '&phone=' + encodeURIComponent(selectedPhone);
        }

        fetch(pollUrl)
            .then(res => res.json())
            .then(data => {
                if (data.contacts) {
                    renderContacts(data.contacts);
                }
                if (data.conversation) {
                    // Only update active conversation if the select parameter matches
                    // to prevent rendering another contact's data on fast transitions.
                    const currentSelected = new URLSearchParams(window.location.search).get('phone') || '';
                    if (currentSelected === selectedPhone) {
                        renderMessages(data.conversation);
                    }
                }
            })
            .catch(err => {
                console.error("Polling error: ", err);
            });
    }

    // Poll every 4 seconds to fetch new messages and contact updates
    if (selectedPhone !== 'new') {
        // Run initial poll shortly after load to sync up
        setTimeout(pollForUpdates, 1000);
        
        // Schedule interval
        setInterval(pollForUpdates, 4000);
    }
});
