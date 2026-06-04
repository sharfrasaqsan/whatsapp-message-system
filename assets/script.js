// assets/script.js

document.addEventListener('DOMContentLoaded', function() {
    const messageForm = document.getElementById('messageForm');
    
    if (messageForm) {
        messageForm.addEventListener('submit', function(e) {
            const phoneInput = document.getElementById('phone_number');
            const messageInput = document.getElementById('message');
            const submitBtn = document.getElementById('submitBtn');
            
            let isValid = true;
            
            // Simple validation
            if (!phoneInput.value.trim()) {
                alert('Please enter a phone number.');
                phoneInput.focus();
                isValid = false;
            } else if (!messageInput.value.trim()) {
                alert('Please enter a message.');
                messageInput.focus();
                isValid = false;
            }
            
            if (!isValid) {
                e.preventDefault();
            } else {
                // Disable button to prevent double submission
                submitBtn.disabled = true;
                submitBtn.textContent = 'Sending...';
            }
        });
    }
});
