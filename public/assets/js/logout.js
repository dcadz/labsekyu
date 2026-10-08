// assets/js/logout.js
function confirmLogout(event) {
    event.preventDefault();
    const url = event.currentTarget.href;
    
    // Create modal overlay
    const overlay = document.createElement('div');
    overlay.style.position = 'fixed';
    overlay.style.top = '0';
    overlay.style.left = '0';
    overlay.style.width = '100vw';
    overlay.style.height = '100vh';
    overlay.style.backgroundColor = 'rgba(0, 0, 0, 0.7)';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    overlay.style.zIndex = '9999';
    overlay.style.backdropFilter = 'blur(5px)';
    overlay.style.animation = 'fadeIn 0.2s ease-out';
    
    // Inject keyframes if not exists
    if (!document.getElementById('logout-modal-styles')) {
        const style = document.createElement('style');
        style.id = 'logout-modal-styles';
        style.innerHTML = `
            @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
            @keyframes scaleUp { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        `;
        document.head.appendChild(style);
    }
    
    // Create modal box
    const modal = document.createElement('div');
    modal.style.backgroundColor = 'var(--bg-card, #1e293b)';
    modal.style.padding = '2rem';
    modal.style.borderRadius = '0.75rem';
    modal.style.border = '1px solid var(--border-color, #334155)';
    modal.style.textAlign = 'center';
    modal.style.boxShadow = '0 10px 25px rgba(0,0,0,0.5)';
    modal.style.maxWidth = '400px';
    modal.style.width = '90%';
    modal.style.animation = 'scaleUp 0.2s ease-out';
    
    // Icon
    const icon = document.createElement('div');
    icon.innerHTML = '<svg style="width: 48px; height: 48px; color: var(--accent-yellow, #eab308); margin-bottom: 1rem;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>';
    
    // Title
    const text = document.createElement('h3');
    text.innerText = 'Log Out';
    text.style.margin = '0 0 0.5rem 0';
    text.style.color = 'var(--text-primary, #f8fafc)';
    text.style.fontSize = '1.25rem';
    
    // Subtitle
    const subtext = document.createElement('p');
    subtext.innerText = 'Are you sure you want to end your session?';
    subtext.style.margin = '0 0 1.5rem 0';
    subtext.style.color = 'var(--text-secondary, #94a3b8)';
    subtext.style.fontSize = '0.875rem';
    
    // Buttons container
    const btnContainer = document.createElement('div');
    btnContainer.style.display = 'flex';
    btnContainer.style.gap = '1rem';
    btnContainer.style.justifyContent = 'center';
    
    // Cancel Button
    const cancelBtn = document.createElement('button');
    cancelBtn.innerText = 'Cancel';
    cancelBtn.style.padding = '0.75rem 1.5rem';
    cancelBtn.style.borderRadius = '0.5rem';
    cancelBtn.style.border = '1px solid var(--border-color, #334155)';
    cancelBtn.style.backgroundColor = 'transparent';
    cancelBtn.style.color = 'var(--text-primary, #f8fafc)';
    cancelBtn.style.cursor = 'pointer';
    cancelBtn.style.fontWeight = '600';
    cancelBtn.style.flex = '1';
    cancelBtn.style.transition = 'background-color 0.2s';
    cancelBtn.onmouseover = () => cancelBtn.style.backgroundColor = 'rgba(255,255,255,0.05)';
    cancelBtn.onmouseout = () => cancelBtn.style.backgroundColor = 'transparent';
    cancelBtn.onclick = () => overlay.remove();
    
    // Confirm Button
    const confirmBtn = document.createElement('button');
    confirmBtn.innerText = 'Yes, Log Out';
    confirmBtn.style.padding = '0.75rem 1.5rem';
    confirmBtn.style.borderRadius = '0.5rem';
    confirmBtn.style.border = 'none';
    confirmBtn.style.backgroundColor = 'var(--accent-red, #ef4444)';
    confirmBtn.style.color = '#fff';
    confirmBtn.style.cursor = 'pointer';
    confirmBtn.style.fontWeight = '600';
    confirmBtn.style.flex = '1';
    confirmBtn.style.transition = 'opacity 0.2s';
    confirmBtn.onmouseover = () => confirmBtn.style.opacity = '0.9';
    confirmBtn.onmouseout = () => confirmBtn.style.opacity = '1';
    confirmBtn.onclick = () => window.location.href = url;
    
    btnContainer.appendChild(cancelBtn);
    btnContainer.appendChild(confirmBtn);
    
    modal.appendChild(icon);
    modal.appendChild(text);
    modal.appendChild(subtext);
    modal.appendChild(btnContainer);
    overlay.appendChild(modal);
    document.body.appendChild(overlay);
}
