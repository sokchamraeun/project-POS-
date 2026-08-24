// menu.js — Shared menu & cart interactions (Fly-to-Cart animation engine)
var product = window.product || {};

function getCsrfToken() {
    if (window.MENU_CONFIG && window.MENU_CONFIG.csrfToken) return window.MENU_CONFIG.csrfToken;
    if (typeof window.CSRF !== 'undefined' && window.CSRF) return window.CSRF;
    if (typeof CSRF !== 'undefined' && CSRF) return CSRF;
    return '';
}

// ── Initialization Hook ──
function initMenu() {
    if (!document.getElementById('toast-container')) {
        const el = document.createElement('div');
        el.id = 'toast-container';
        document.body.appendChild(el);
    }
    _bindModalDismiss();
    _bindProductCards();
    _bindChatInput();
}

window.initMenuEvents = function() {
    initMenu();
};
window.initMenu = initMenu;

document.addEventListener('DOMContentLoaded', initMenu);
document.addEventListener('pageLoaded', function(e) {
    if (e.detail && e.detail.href && e.detail.href.includes('menu.php')) {
        initMenu();
    }
});

// ─────────────────────────────────────────────
//  MODAL
// ─────────────────────────────────────────────
function openModal(id, name, price, img, cat, desc, badge, hasSizes, sizes, addons, promo) {
    const p = Number(price) || 0;
    product = { id, name, price: p, cat, promo: promo || 0 };
    if (typeof modalQty !== 'undefined') window.modalQty = 1;
    if (typeof modalUnitPrice !== 'undefined') window.modalUnitPrice = p;
    if (typeof modalAddonTotal !== 'undefined') window.modalAddonTotal = 0;

    const modalImg   = document.getElementById('modalImg')   || document.getElementById('modalImage');
    const modalName  = document.getElementById('modalName');
    const modalDesc  = document.getElementById('modalDesc');
    const modalPrice = document.getElementById('modalPrice');
    const modalEl    = document.getElementById('modal')      || document.getElementById('product-modal') || document.getElementById('customModal');

    if (modalImg)   modalImg.src            = img || '';
    if (modalName)  modalName.textContent  = name || '';
    if (modalDesc)  modalDesc.textContent  = desc || '';
    if (modalPrice) modalPrice.textContent = '$' + p.toFixed(2);

    const isJuice = cat === 'Juice';
    const isHot   = cat === 'Hot';

    _show('sweetnessGroup', !isJuice);
    _show('iceGroup',       !isJuice && !isHot);
    _show('milkGroup',      !isJuice);

    if (typeof updateModalTotal === 'function') updateModalTotal();

    if (modalEl) {
        modalEl.style.display = 'flex';
        modalEl.setAttribute('aria-hidden', 'false');
    }
}

function closeModal() {
    const modalEl = document.getElementById('modal') || document.getElementById('product-modal') || document.getElementById('customModal');
    if (modalEl) {
        modalEl.style.display = 'none';
        modalEl.setAttribute('aria-hidden', 'true');
    }
    document.body.style.overflow = '';
}

let _modalDismissBound = false;
function _bindModalDismiss() {
    if (_modalDismissBound) return;
    _modalDismissBound = true;

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('modal') || document.getElementById('product-modal') || document.getElementById('customModal');
        if (modal && e.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeModal();
    });
}

// ─────────────────────────────────────────────
//  FLY-TO-CART ANIMATION ENGINE
// ─────────────────────────────────────────────
function flyToCart(sourceElement) {
    if (!sourceElement) return;

    let sourceImg = null;
    if (sourceElement.tagName === 'IMG') {
        sourceImg = sourceElement;
    } else if (sourceElement.querySelector) {
        sourceImg = sourceElement.querySelector('img');
    }

    if (!sourceImg || !sourceImg.src) return;

    const rect = sourceImg.getBoundingClientRect();
    if (!rect || rect.width === 0 || rect.height === 0) return;

    // Target: Cart Sidebar Header Count (#cpCount) if open, or Header Cart Button if closed
    const sidebar = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    const isSidebarOpen = sidebar && !sidebar.classList.contains('hidden') && sidebar.style.display !== 'none' && window.getComputedStyle(sidebar).display !== 'none';

    let targetEl = null;
    if (isSidebarOpen) {
        targetEl = document.getElementById('cpCount') || 
                   sidebar.querySelector('.cp-count') || 
                   sidebar.querySelector('.cp-title') || 
                   sidebar.querySelector('.cp-header');
    } else {
        targetEl = document.getElementById('cart-badge') || 
                   document.getElementById('cart-toggle-btn') || 
                   document.getElementById('cartToggleBtn') || 
                   document.querySelector('.header-right .cart-pill-btn') ||
                   document.querySelector('.header-right .btn-nav') ||
                   document.querySelector('.cart-toggle-btn');
    }

    let targetX = window.innerWidth - 65;
    let targetY = 28;

    if (targetEl) {
        const tRect = targetEl.getBoundingClientRect();
        if (tRect.width > 0 && tRect.left > 0) {
            targetX = tRect.left + (tRect.width / 2) - 15;
            targetY = tRect.top + (tRect.height / 2) - 15;
        }
    }

    const dx = targetX - rect.left;
    const dy = targetY - rect.top;

    const clone = document.createElement('img');
    clone.src = sourceImg.src;
    clone.className = 'fly-to-cart-clone';
    clone.style.position = 'fixed';
    clone.style.top = rect.top + 'px';
    clone.style.left = rect.left + 'px';
    clone.style.width = rect.width + 'px';
    clone.style.height = rect.height + 'px';
    clone.style.objectFit = 'contain';
    clone.style.borderRadius = '16px';
    clone.style.pointerEvents = 'none';
    clone.style.zIndex = '2147483646';
    clone.style.boxShadow = '0 12px 28px rgba(0, 0, 0, 0.4)';
    clone.style.transformOrigin = 'center center';
    document.body.appendChild(clone);

    function triggerCartBump() {
        // Clean update without scale animation
    }

    if (typeof clone.animate === 'function') {
        const anim = clone.animate([
            {
                transform: 'translate(0px, 0px) scale(1) rotate(0deg)',
                opacity: 1,
                borderRadius: '16px'
            },
            {
                transform: `translate(${dx * 0.5}px, ${dy * 0.3 - 25}px) scale(0.65) rotate(-6deg)`,
                opacity: 0.9,
                offset: 0.45
            },
            {
                transform: `translate(${dx}px, ${dy}px) scale(0.18) rotate(10deg)`,
                opacity: 0.1,
                borderRadius: '50%'
            }
        ], {
            duration: 260,
            easing: 'cubic-bezier(0.2, 0.8, 0.25, 1)',
            fill: 'forwards'
        });

        anim.onfinish = function () {
            clone.remove();
            triggerCartBump();
        };
    } else {
        clone.style.transition = 'transform 0.26s cubic-bezier(0.2, 0.8, 0.25, 1), opacity 0.26s cubic-bezier(0.2, 0.8, 0.25, 1), border-radius 0.26s ease';
        requestAnimationFrame(() => {
            clone.style.transform = `translate(${dx}px, ${dy}px) scale(0.2) rotate(10deg)`;
            clone.style.opacity = '0.1';
            clone.style.borderRadius = '50%';
        });
        setTimeout(() => {
            clone.remove();
            triggerCartBump();
        }, 260);
    }
}
window.flyToCart = flyToCart;

// ─────────────────────────────────────────────
//  ADD TO CART (from modal)
// ─────────────────────────────────────────────
function addToCart() {
    const token = getCsrfToken();
    const pId = (typeof product !== 'undefined' && product.id) ? product.id : 0;
    if (!pId) return;

    const modalImg = document.getElementById('modalImg') || document.getElementById('modalImage');
    if (modalImg && modalImg.src) {
        flyToCart(modalImg);
    } else {
        const card = document.querySelector(`.product-card[data-product-id="${pId}"]`);
        if (card) flyToCart(card);
    }

    const qtyVal = (typeof modalQty !== 'undefined' ? modalQty : 1);
    const params = new URLSearchParams({ id: pId, qty: qtyVal, csrf_token: token });

    if (typeof getPillValue === 'function') {
        const _optSw = document.getElementById('optSweetness');
        if (_optSw && _optSw.style.display !== 'none') params.append('sweetness', getPillValue('sweetnessPills'));
        const _optIce = document.getElementById('optIce');
        if (_optIce && _optIce.style.display !== 'none') params.append('ice', getPillValue('icePills'));
    } else {
        if (_isVisible('sweetnessGroup')) {
            const swEl = document.getElementById('sweetnessSelect');
            if (swEl) params.append('sweetness', swEl.value);
        }
        if (_isVisible('iceGroup')) {
            const iceEl = document.getElementById('iceSelect');
            if (iceEl) params.append('ice', iceEl.value);
        }
        if (_isVisible('milkGroup')) {
            const milkEl = document.getElementById('milkSelect');
            if (milkEl) params.append('milk', milkEl.value);
        }
    }

    if (typeof closeModal === 'function') closeModal();

    _postCart(params).then(data => {
        if (!data || !data.success) {
            showToast((data && data.message) ? data.message : 'Error adding to cart', 'error');
            if (typeof window.loadCartPanel === 'function') window.loadCartPanel();
            return;
        }
        _updateCartCount(data.cart_count);
        if (data.cart && typeof renderCartPanel === 'function') {
            renderCartPanel(data.cart);
        } else if (typeof window.loadCartPanel === 'function') {
            window.loadCartPanel();
        } else if (typeof loadCartPanel === 'function') {
            loadCartPanel();
        }
    }).catch(err => {
        showToast((err && err.message) ? err.message : 'Error adding to cart', 'error');
        if (typeof window.loadCartPanel === 'function') window.loadCartPanel();
    });
}

// ─────────────────────────────────────────────
//  QUICK ADD (no customisation)
// ─────────────────────────────────────────────
function quickAdd(productId, eventOrPrice) {
    if (eventOrPrice && typeof eventOrPrice.stopPropagation === 'function') {
        eventOrPrice.stopPropagation();
    }

    const card = document.querySelector(`.product-card[data-product-id="${productId}"]`);
    if (card) {
        flyToCart(card);
    }

    const token = getCsrfToken();
    const params = new URLSearchParams({ id: productId, qty: 1, csrf_token: token });

    _postCart(params).then(data => {
        if (!data || !data.success) {
            showToast((data && data.message) ? data.message : 'Error adding to cart', 'error');
            if (typeof window.loadCartPanel === 'function') window.loadCartPanel();
            return;
        }
        _updateCartCount(data.cart_count);
        if (data.cart && typeof renderCartPanel === 'function') {
            renderCartPanel(data.cart);
        } else if (typeof window.loadCartPanel === 'function') {
            window.loadCartPanel();
        } else if (typeof loadCartPanel === 'function') {
            loadCartPanel();
        }
    }).catch(err => {
        showToast((err && err.message) ? err.message : 'Error adding to cart', 'error');
        if (typeof window.loadCartPanel === 'function') window.loadCartPanel();
    });
}

// ─────────────────────────────────────────────
//  SHARED FETCH HELPER
// ─────────────────────────────────────────────
function _postCart(params) {
    return fetch('add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: params.toString()
    }).then(res => {
        return res.json().catch(() => ({ success: false, message: 'HTTP ' + res.status + ' error' }));
    }).catch(err => ({ success: false, message: err.message || 'Network error' }));
}

// ─────────────────────────────────────────────
//  CART BADGE
// ─────────────────────────────────────────────
function _updateCartCount(count) {
    if (count == null) return;
    let badge = document.querySelector('.cart-count');
    if (badge) {
        badge.textContent = count;
    } else {
        const cartIcon = document.querySelector('.cart-icon');
        if (cartIcon) {
            badge = document.createElement('span');
            badge.className = 'cart-count';
            badge.textContent = count;
            cartIcon.appendChild(badge);
        }
    }
    if (badge) badge.style.display = (parseInt(count, 10) === 0) ? 'none' : '';
}

// ─────────────────────────────────────────────
//  TOAST (Max 2 concurrent alerts)
// ─────────────────────────────────────────────
function showToast(message, type = 'success', duration = 2800) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        document.body.appendChild(container);
    }

    const MAX_VISIBLE_TOASTS = 2;
    const activeToasts = container.querySelectorAll('.toast:not(.hide)');
    if (activeToasts.length >= MAX_VISIBLE_TOASTS) {
        const toRemoveCount = activeToasts.length - MAX_VISIBLE_TOASTS + 1;
        for (let i = 0; i < toRemoveCount; i++) {
            const oldest = activeToasts[i];
            if (oldest && !oldest.classList.contains('hide')) {
                oldest.classList.remove('show');
                oldest.classList.add('hide');
                setTimeout(() => {
                    if (oldest && oldest.parentNode) oldest.parentNode.removeChild(oldest);
                }, 200);
            }
        }
    }

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = message;
    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('show'));

    setTimeout(() => {
        toast.classList.remove('show');
        toast.classList.add('hide');
        setTimeout(() => toast.remove(), 250);
    }, duration);
}

// ─────────────────────────────────────────────
//  DYNAMIC EVENT DELEGATION (Drink Cards & Quick Add)
// ─────────────────────────────────────────────
let _menuDelegationBound = false;
function _bindProductCards() {
    if (_menuDelegationBound) return;
    _menuDelegationBound = true;

    // Delegated click listener on document for dynamically swapped drink cards
    document.addEventListener('click', function (e) {
        // Quick Add button check
        const quickBtn = e.target.closest('.quick-add-btn, [data-quick-add]');
        if (quickBtn) {
            const pid = quickBtn.dataset.productId || quickBtn.dataset.quickAdd || quickBtn.dataset.id;
            if (pid && typeof quickAdd === 'function' && !quickBtn.getAttribute('onclick')) {
                e.stopPropagation();
                quickAdd(pid, e);
                return;
            }
        }

        // Open Product modal check for cart items
        const cartItem = e.target.closest('.cp-item, .js-cart-item-open');
        if (cartItem && !e.target.closest('button, a, input, select, .cp-qty, .cp-remove')) {
            const idx = cartItem.dataset.cartIndex !== undefined ? parseInt(cartItem.dataset.cartIndex, 10) : parseInt((cartItem.id || '').replace('cp-item-', ''), 10);
            if (!isNaN(idx) && typeof openCartItemEditModal === 'function') {
                e.stopPropagation();
                openCartItemEditModal(idx);
                return;
            }
        }

        // Open Product modal check for drink cards
        const card = e.target.closest('.js-open-product, .product-card, .seller-card, .drink-card, [data-product-id], [data-id]');
        if (card && (card.classList.contains('disabled') || card.classList.contains('out-of-stock-card') || card.dataset.stockStatus === 'out_of_stock')) {
            return;
        }
        if (card && !e.target.closest('button, a, input, select, .quick-add-btn, [data-quick-add]') && !card.getAttribute('onclick')) {
            if (typeof openModalFromCard === 'function') {
                openModalFromCard(card);
            } else if (typeof openModal === 'function') {
                var sizes = [], addons = [];
                try { sizes = JSON.parse(card.dataset.productSizes || card.dataset.sizes || '[]'); } catch (_) {}
                try { addons = JSON.parse(card.dataset.productAddons || card.dataset.addons || '[]'); } catch (_) {}
                openModal(
                    card.dataset.productId || card.dataset.id,
                    card.dataset.productName || card.dataset.name || '',
                    Number(card.dataset.productPrice || card.dataset.price || 0),
                    card.dataset.productImage || card.dataset.image || '',
                    card.dataset.productCategory || card.dataset.category || '',
                    card.dataset.productDesc || card.dataset.description || '',
                    card.dataset.productBadge || card.dataset.badge || '',
                    card.dataset.productHasSizes === '1' || card.dataset.hasSizes === '1',
                    sizes,
                    addons,
                    Number(card.dataset.productPromo || card.dataset.promo || 0)
                );
            }
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            const card = e.target.closest('.js-open-product, .product-card, .seller-card, .drink-card');
            if (card) {
                e.preventDefault();
                if (typeof openModalFromCard === 'function') {
                    openModalFromCard(card);
                }
            }
        }
    });
}

// ─────────────────────────────────────────────
//  CART SIDEBAR TOGGLE & EVENT DELEGATION
// ─────────────────────────────────────────────
function updateCartIconVisibility(isOpen) {
    const btn = document.getElementById('cart-toggle-btn') || document.getElementById('cartToggleBtn');
    if (btn) {
        if (isOpen) {
            btn.style.setProperty('display', 'none', 'important');
        } else {
            btn.style.setProperty('display', 'flex', 'important');
        }
    }
}

function openCartSidebar() {
    const sidebar = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!sidebar) return;
    sidebar.classList.remove('hidden');
    sidebar.style.setProperty('display', 'flex', 'important');
    localStorage.setItem('cart_sidebar_closed', 'false');
    updateCartIconVisibility(true);
}

function closeCartSidebar() {
    const sidebar = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!sidebar) return;
    sidebar.classList.add('hidden');
    sidebar.style.setProperty('display', 'none', 'important');
    localStorage.setItem('cart_sidebar_closed', 'true');
    updateCartIconVisibility(false);
}

function toggleCartSidebar() {
    const sidebar = document.getElementById('cart-sidebar') || document.getElementById('cartPanel');
    if (!sidebar) return;
    const isHidden = sidebar.classList.contains('hidden') || sidebar.style.display === 'none' || window.getComputedStyle(sidebar).display === 'none';
    if (isHidden) {
        openCartSidebar();
    } else {
        closeCartSidebar();
    }
}

function closeCart() {
    closeCartSidebar();
}

window.openCartSidebar = openCartSidebar;
window.closeCartSidebar = closeCartSidebar;
window.toggleCartSidebar = toggleCartSidebar;
window.closeCart = closeCart;

// Global Event Delegation for Cart Toggle (#cart-toggle-btn) & Close (#close-cart-btn)
document.addEventListener('click', function(e) {
    const toggleBtn = e.target.closest('#cart-toggle-btn, #cartToggleBtn, [data-cart-toggle]');
    if (toggleBtn) {
        e.preventDefault();
        toggleCartSidebar();
        return;
    }

    const closeBtn = e.target.closest('#close-cart-btn, .cp-close-btn, [data-close-cart]');
    if (closeBtn) {
        e.preventDefault();
        closeCartSidebar();
        return;
    }
});

// ─────────────────────────────────────────────
//  CHAT
// ─────────────────────────────────────────────
function toggleChat() {
    const box = document.getElementById('chatBox');
    if (!box) return;
    const isOpen = box.style.display === 'flex';
    box.style.display = isOpen ? 'none' : 'flex';
    if (!isOpen) document.getElementById('chatInput')?.focus();
}

function sendChat() {
    const input = document.getElementById('chatInput');
    if (!input) return;
    const msg = input.value.trim();
    if (!msg) return;

    const chat    = document.getElementById('chatMessages');
    const sendBtn = document.querySelector('#chatBox .send-btn');

    _appendChatBubble(chat, msg, 'user');
    input.value     = '';
    if (sendBtn) sendBtn.disabled = true;

    fetch('chatbot.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'message=' + encodeURIComponent(msg)
    })
        .then(res => res.json())
        .then(data => {
            _appendChatBubble(chat, data.reply || "Sorry, I didn't catch that.", 'bot');
        })
        .catch(() => {
            _appendChatBubble(chat, 'Sorry, I\'m having trouble connecting. Please try again later.', 'bot');
        })
        .finally(() => {
            if (sendBtn) sendBtn.disabled = false;
        });
}

function _appendChatBubble(container, text, role) {
    if (!container) return;
    const wrap = document.createElement('div');
    wrap.className = `chat-bubble-wrap chat-${role}`;

    const bubble = document.createElement('div');
    bubble.className = 'chat-bubble';
    bubble.textContent = text;

    const icon = document.createElement('div');
    icon.className = 'chat-avatar';
    icon.innerHTML = role === 'user'
        ? '<i class="fa-solid fa-user"></i>'
        : '<i class="fa-solid fa-robot"></i>';

    if (role === 'user') {
        wrap.appendChild(bubble);
        wrap.appendChild(icon);
    } else {
        wrap.appendChild(icon);
        wrap.appendChild(bubble);
    }

    container.appendChild(wrap);
    container.scrollTop = container.scrollHeight;
}

let _chatInputBound = false;
function _bindChatInput() {
    if (_chatInputBound) return;
    const chatInput = document.getElementById('chatInput');
    if (!chatInput) return;
    _chatInputBound = true;
    chatInput.addEventListener('keypress', e => {
        if (e.key === 'Enter') sendChat();
    });
}

// ─────────────────────────────────────────────
//  UTILS
// ─────────────────────────────────────────────
function _show(id, visible) {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? 'block' : 'none';
}

function _isVisible(id) {
    const el = document.getElementById(id);
    return el && el.style.display !== 'none';
}
