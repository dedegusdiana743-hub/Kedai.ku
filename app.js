/**
 * KEDAI KU - demo tampilan statis
 * Menangani: filter menu, keranjang, pilihan pengiriman, dan ringkasan total.
 */
(function () {
    'use strict';

    var CART_KEY = 'kedaiku_cart_v1';
    var DELIVERY_KEY = 'kedaiku_delivery_v1';
    var TAX_RATE = 0.10;
    var SHIPPING_FEE = 7000;

    var menuList = document.querySelector('[data-menu-list]');
    var cartPanel = document.querySelector('[data-cart-panel]');
    var cartOverlay = document.querySelector('[data-cart-overlay]');
    var cartItems = document.querySelector('[data-cart-items]');
    var cartEmpty = document.querySelector('[data-cart-empty]');
    var cartCount = document.querySelector('[data-cart-count]');
    var toast = document.querySelector('[data-toast]');
    var summarySubtotal = document.querySelector('[data-summary-subtotal]');
    var summaryShipping = document.querySelector('[data-summary-shipping]');
    var summaryTax = document.querySelector('[data-summary-tax]');
    var summaryTotal = document.querySelector('[data-summary-total]');
    var deliveryButtons = Array.prototype.slice.call(document.querySelectorAll('[data-delivery]'));
    var checkoutPanel = document.querySelector('[data-checkout-panel]');
    var checkoutClose = document.querySelector('[data-checkout-close]');
    var checkoutBack = document.querySelector('[data-checkout-back]');
    var checkoutWhatsApp = document.querySelector('[data-checkout-whatsapp]');
    var checkoutItems = document.querySelector('[data-checkout-items]');
    var checkoutEmpty = document.querySelector('[data-checkout-empty]');
    var checkoutSubtotal = document.querySelector('[data-checkout-subtotal]');
    var checkoutShipping = document.querySelector('[data-checkout-shipping]');
    var checkoutTax = document.querySelector('[data-checkout-tax]');
    var checkoutTotal = document.querySelector('[data-checkout-total]');
    var checkoutDeliveryButtons = Array.prototype.slice.call(document.querySelectorAll('[data-checkout-delivery]'));

    var cart = loadCart();
    var delivery = localStorage.getItem(DELIVERY_KEY) === 'DELIVERY' ? 'DELIVERY' : 'PICKUP';

    function loadCart() {
        try {
            var raw = localStorage.getItem(CART_KEY);
            var parsed = raw ? JSON.parse(raw) : [];
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    }

    function saveCart() {
        try {
            localStorage.setItem(CART_KEY, JSON.stringify(cart));
        } catch (error) {
            /* penyimpanan dibrowser mungkin dinonaktifkan */
        }
    }

    function rupiah(value) {
        return 'Rp' + Math.round(value).toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    }

    function showToast(message) {
        if (!toast) {
            return;
        }
        toast.textContent = message;
        toast.classList.add('is-visible');
        window.clearTimeout(showToast.timer);
        showToast.timer = window.setTimeout(function () {
            toast.classList.remove('is-visible');
        }, 2200);
    }

    function findItem(name) {
        for (var i = 0; i < cart.length; i += 1) {
            if (cart[i].name === name) {
                return cart[i];
            }
        }
        return null;
    }

    function addToCart(card) {
        var name = card.getAttribute('data-name');
        var price = parseInt(card.getAttribute('data-price'), 10) || 0;
        var item = findItem(name);
        if (item) {
            item.qty = Math.min(99, item.qty + 1);
        } else {
            cart.push({ name: name, price: price, qty: 1 });
        }
        saveCart();
        renderCart();
        showToast(name + ' ditambahkan ke keranjang');
    }

    function changeQty(name, delta) {
        for (var i = 0; i < cart.length; i += 1) {
            if (cart[i].name === name) {
                cart[i].qty = Math.min(99, Math.max(0, cart[i].qty + delta));
                if (cart[i].qty === 0) {
                    cart.splice(i, 1);
                }
                break;
            }
        }
        saveCart();
        renderCart();
    }

    function removeItem(name) {
        cart = cart.filter(function (item) {
            return item.name !== name;
        });
        saveCart();
        renderCart();
    }

    function totals(items, delivery) {
        var list = items || cart;
        var subtotal = list.reduce(function (sum, item) {
            return sum + item.price * item.qty;
        }, 0);
        var shipping = delivery === 'DELIVERY' && subtotal > 0 ? SHIPPING_FEE : 0;
        var tax = Math.round(subtotal * TAX_RATE);
        return { subtotal: subtotal, shipping: shipping, tax: tax, total: subtotal + shipping + tax };
    }

    function renderCart() {
        var count = cart.reduce(function (sum, item) {
            return sum + item.qty;
        }, 0);

        if (cartCount) {
            cartCount.textContent = String(count);
            cartCount.hidden = count === 0;
        }
        if (cartEmpty) {
            cartEmpty.hidden = count > 0;
        }

        if (cartItems) {
            cartItems.innerHTML = '';
            cart.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'cart-item';

                var info = document.createElement('div');
                var name = document.createElement('h4');
                name.textContent = item.name;
                var price = document.createElement('small');
                price.textContent = rupiah(item.price) + ' × ' + item.qty;
                info.appendChild(name);
                info.appendChild(price);

                var controls = document.createElement('div');
                controls.className = 'qty';
                var minus = document.createElement('button');
                minus.type = 'button';
                minus.textContent = '−';
                minus.setAttribute('aria-label', 'Kurangi ' + item.name);
                minus.addEventListener('click', function () {
                    changeQty(item.name, -1);
                });
                var qty = document.createElement('span');
                qty.textContent = String(item.qty);
                var plus = document.createElement('button');
                plus.type = 'button';
                plus.textContent = '+';
                plus.setAttribute('aria-label', 'Tambah ' + item.name);
                plus.addEventListener('click', function () {
                    changeQty(item.name, 1);
                });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove';
                remove.textContent = 'hapus';
                remove.setAttribute('aria-label', 'Hapus ' + item.name);
                remove.addEventListener('click', function () {
                    removeItem(item.name);
                });

                controls.appendChild(minus);
                controls.appendChild(qty);
                controls.appendChild(plus);
                controls.appendChild(remove);
                row.appendChild(info);
                row.appendChild(controls);
                cartItems.appendChild(row);
            });
        }

        var value = totals(cart, delivery);
        if (summarySubtotal) summarySubtotal.textContent = rupiah(value.subtotal);
        if (summaryShipping) summaryShipping.textContent = rupiah(value.shipping);
        if (summaryTax) summaryTax.textContent = rupiah(value.tax);
        if (summaryTotal) summaryTotal.textContent = rupiah(value.total);
        renderCheckout();
    }

    function renderCheckout() {
        var value = totals(cart, delivery);

        if (checkoutEmpty) {
            checkoutEmpty.hidden = cart.length > 0;
        }
        if (checkoutItems) {
            checkoutItems.innerHTML = '';
            cart.forEach(function (item) {
                var row = document.createElement('div');
                row.className = 'cart-item';

                var info = document.createElement('div');
                var name = document.createElement('h4');
                name.textContent = item.name;
                var price = document.createElement('small');
                price.textContent = rupiah(item.price) + ' × ' + item.qty;
                info.appendChild(name);
                info.appendChild(price);

                var controls = document.createElement('div');
                controls.className = 'qty';
                var minus = document.createElement('button');
                minus.type = 'button';
                minus.textContent = '−';
                minus.setAttribute('aria-label', 'Kurangi ' + item.name);
                minus.addEventListener('click', function () {
                    changeQty(item.name, -1);
                });
                var qty = document.createElement('span');
                qty.textContent = String(item.qty);
                var plus = document.createElement('button');
                plus.type = 'button';
                plus.textContent = '+';
                plus.setAttribute('aria-label', 'Tambah ' + item.name);
                plus.addEventListener('click', function () {
                    changeQty(item.name, 1);
                });
                var remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'remove';
                remove.textContent = 'hapus';
                remove.setAttribute('aria-label', 'Hapus ' + item.name);
                remove.addEventListener('click', function () {
                    removeItem(item.name);
                });

                controls.appendChild(minus);
                controls.appendChild(qty);
                controls.appendChild(plus);
                controls.appendChild(remove);
                row.appendChild(info);
                row.appendChild(controls);
                checkoutItems.appendChild(row);
            });
        }

        if (checkoutSubtotal) checkoutSubtotal.textContent = rupiah(value.subtotal);
        if (checkoutShipping) checkoutShipping.textContent = rupiah(value.shipping);
        if (checkoutTax) checkoutTax.textContent = rupiah(value.tax);
        if (checkoutTotal) checkoutTotal.textContent = rupiah(value.total);
    }

    function openCheckout() {
        if (cartOverlay) cartOverlay.hidden = true;
        if (cartPanel) cartPanel.hidden = true;
        if (checkoutPanel) checkoutPanel.hidden = false;
        document.body.style.overflow = 'hidden';
        renderCheckout();
    }

    function closeCheckout() {
        if (checkoutPanel) checkoutPanel.hidden = true;
        if (cartPanel) cartPanel.hidden = false;
        if (cartOverlay) cartOverlay.hidden = true;
        document.body.style.overflow = '';
    }

    function applyDelivery() {
        deliveryButtons.forEach(function (button) {
            var active = button.getAttribute('data-delivery') === delivery;
            button.classList.toggle('is-selected', active);
            if (active) {
                button.setAttribute('aria-current', 'true');
            } else {
                button.removeAttribute('aria-current');
            }
        });
        localStorage.setItem(DELIVERY_KEY, delivery);
        renderCart();
        renderCheckout();
    }

    function openCart() {
        if (cartPanel) cartPanel.hidden = false;
        if (cartOverlay) cartOverlay.hidden = false;
        document.body.style.overflow = 'hidden';
    }

    function closeCart() {
        if (cartPanel) cartPanel.hidden = true;
        if (cartOverlay) cartOverlay.hidden = true;
        document.body.style.overflow = '';
    }

    if (menuList) {
        menuList.addEventListener('click', function (event) {
            var button = event.target.closest('[data-add]');
            if (!button) {
                return;
            }
            var card = button.closest('.menu-card');
            if (card) {
                addToCart(card);
            }
        });
    }

    document.querySelectorAll('[data-filter]').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('[data-filter]').forEach(function (item) {
                item.classList.toggle('is-active', item === chip);
            });
            var filter = chip.getAttribute('data-filter');
            document.querySelectorAll('.menu-card').forEach(function (card) {
                var visible = filter === 'all' || card.getAttribute('data-category') === filter;
                card.hidden = !visible;
            });
        });
    });

    deliveryButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            delivery = button.getAttribute('data-delivery') === 'DELIVERY' ? 'DELIVERY' : 'PICKUP';
            applyDelivery();
        });
    });

    checkoutDeliveryButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            delivery = button.getAttribute('data-checkout-delivery') === 'DELIVERY' ? 'DELIVERY' : 'PICKUP';
            applyDelivery();
        });
    });

    var openButton = document.querySelector('[data-cart-open]');
    if (openButton) {
        openButton.addEventListener('click', openCart);
    }
    var closeButton = document.querySelector('[data-cart-close]');
    if (closeButton) {
        closeButton.addEventListener('click', closeCart);
    }
    if (cartOverlay) {
        cartOverlay.addEventListener('click', closeCart);
    }
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            if (checkoutPanel && !checkoutPanel.hidden) {
                closeCheckout();
            } else {
                closeCart();
            }
        }
    });

    var checkoutLink = document.querySelector('[data-checkout]');
    if (checkoutLink) {
        checkoutLink.addEventListener('click', function (event) {
            var count = cart.reduce(function (sum, item) {
                return sum + item.qty;
            }, 0);
            if (count === 0) {
                event.preventDefault();
                showToast('Keranjang masih kosong');
                return;
            }
            event.preventDefault();
            openCheckout();
        });
    }

    if (checkoutClose) {
        checkoutClose.addEventListener('click', closeCheckout);
    }
    if (checkoutBack) {
        checkoutBack.addEventListener('click', closeCheckout);
    }
    if (checkoutWhatsApp) {
        checkoutWhatsApp.addEventListener('click', function (event) {
            event.preventDefault();
            var value = totals(cart, delivery);
            var lines = ['KEDAI KU — Pesanan'];
            cart.forEach(function (item) {
                lines.push('- ' + item.name + ' x' + item.qty + ' (' + rupiah(item.price * item.qty) + ')');
            });
            lines.push('- Subtotal: ' + rupiah(value.subtotal));
            if (value.shipping > 0) {
                lines.push('- Ongkos antar: ' + rupiah(value.shipping));
            }
            lines.push('- Pajak: ' + rupiah(value.tax));
            lines.push('- Total: ' + rupiah(value.total));
            lines.push('');
            lines.push('Kirim pesanan via WhatsApp untuk pembayaran / COD.');
            window.open('https://wa.me/628123456789?text=' + encodeURIComponent(lines.join('\n')), '_blank', 'noopener');
            closeCheckout();
            showToast('Pesanan dikirim ke WhatsApp');
        });
    }

    document.querySelectorAll('[data-app-link]').forEach(function (link) {
        link.addEventListener('click', function (event) {
            if (link.hasAttribute('data-checkout')) {
                return;
            }
            event.preventDefault();
            showToast('Aplikasi penuh berjalan di server KEDAI KU');
        });
    });

    applyDelivery();
    renderCart();
}());
