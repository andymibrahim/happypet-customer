/**
 * HappyPet — Frontend Application Controller
 * Handles Left Navigation, Popup Booking Modal, Midtrans Snap Payment Gateway,
 * Purchase History Filtering, Receipt Printing, and Profile/Pet Management.
 */

let currentActiveBooking = null;
let selectedPaymentChannel = 'QRIS (GoPay / M-Banking)';
let selectedVaNumber = 'QRIS-HP-88291022';

// Format number to Rupiah
function formatIDR(num) {
    return 'Rp ' + Number(num || 0).toLocaleString('id-ID');
}

// Real-Time User Interaction Tracker -> SQL (user_activity_logs)
function trackUserInteraction(actionType, description, targetEntity = 'ui', targetId = null, metadata = {}) {
    try {
        fetch('api.php?action=log_interaction', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            keepalive: true,
            body: JSON.stringify({
                action_type: actionType,
                description: description,
                target_entity: targetEntity,
                target_id: targetId !== null ? String(targetId) : null,
                metadata: metadata
            })
        }).catch(() => {});
    } catch (e) {
        // Non-blocking
    }
}

// Show Toast Notification
function showToast(message, type = 'success') {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = 'hp-toast';
    const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
    toast.style.borderLeftColor = type === 'error' ? '#EF4444' : '#D4A373';
    toast.innerHTML = `<i class="fa-solid ${icon}" style="color:#E6CCB2;font-size:18px;"></i> <span>${message}</span>`;
    container.appendChild(toast);
    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s ease';
        setTimeout(() => toast.remove(), 300);
    }, 4200);
}

// Switch Right Dashboard Section from Left Sidebar Navigation
function switchSection(sectionId) {
    document.querySelectorAll('.dashboard-section').forEach(sec => {
        sec.classList.remove('active');
    });
    const target = document.getElementById('section-' + sectionId);
    if (target) {
        target.classList.add('active');
    }

    document.querySelectorAll('.sidebar-nav .nav-item[data-section]').forEach(btn => {
        btn.classList.toggle('active', btn.getAttribute('data-section') === sectionId);
    });

    // Close mobile sidebar if open
    const sidebar = document.getElementById('leftSidebar');
    if (sidebar) sidebar.classList.remove('mobile-open');

    // Update URL query param cleanly
    const url = new URL(window.location.href);
    url.searchParams.set('tab', sectionId);
    window.history.replaceState({}, '', url);
    window.scrollTo({ top: 0, behavior: 'smooth' });

    const tabNames = {
        dashboard: 'Dashboard',
        packages: 'Layanan & Paket',
        history: 'Riwayat & Struk',
        pets: 'Data Hewan Saya',
        profile: 'Edit Profil Saya'
    };
    trackUserInteraction(
        'view_tab',
        `Membuka menu ${tabNames[sectionId] || sectionId}`,
        'tab',
        sectionId,
        { tab: sectionId }
    );
}

function toggleMobileSidebar() {
    const sidebar = document.getElementById('leftSidebar');
    if (sidebar) sidebar.classList.toggle('mobile-open');
}

// ==========================================================================
// POPUP PEMESANAN PENITIPAN HEWAN (#bookingModal)
// ==========================================================================
function openBookingModal(packageId = null) {
    const modal = document.getElementById('bookingModal');
    if (!modal) return;

    const selectPkg = document.getElementById('bookingPackageSelect');
    if (packageId && selectPkg) {
        selectPkg.value = String(packageId);
    }

    recalculateBookingPopup();
    modal.classList.add('open');

    const selectedOpt = selectPkg ? selectPkg.options[selectPkg.selectedIndex] : null;
    const pkgName = selectedOpt ? (selectedOpt.dataset.name || selectedOpt.text) : 'Default';
    trackUserInteraction(
        'open_booking_modal',
        `Membuka popup pemesanan penitipan (Paket: ${pkgName})`,
        'package',
        packageId || (selectPkg ? selectPkg.value : null),
        { package_name: pkgName }
    );
}

function closeBookingModal() {
    const modal = document.getElementById('bookingModal');
    if (modal) modal.classList.remove('open');
}

// Fill Pet Form inside Booking Popup when user clicks one of their saved pets
function fillSavedPetInBooking(petId) {
    if (!window.HAPPYPET_PETS) return;
    const pet = window.HAPPYPET_PETS.find(p => Number(p.id) === Number(petId));
    if (!pet) return;

    document.getElementById('bookingPetName').value = pet.name || '';
    document.getElementById('bookingPetSpecies').value = pet.species || 'Kucing';
    document.getElementById('bookingPetBreed').value = pet.breed || '';
    document.getElementById('bookingPetWeight').value = pet.weight || 3.5;
    document.getElementById('bookingPetAge').value = pet.age || '1 Tahun';
    if (pet.special_notes) {
        document.getElementById('bookingSpecialNotes').value = pet.special_notes;
    }

    document.querySelectorAll('.saved-pet-chip').forEach(chip => {
        chip.classList.toggle('active', Number(chip.dataset.petId) === Number(petId));
    });

    showToast(`Data anabul "${pet.name}" (${pet.breed}) berhasil dimuat ke formulir.`);
    trackUserInteraction(
        'select_saved_pet',
        `Memilih anabul tersimpan "${pet.name}" (${pet.species}) pada form booking`,
        'pet',
        pet.id,
        { pet_name: pet.name, species: pet.species }
    );
}

// Recalculate Duration & Total Price in Booking Popup in Real-Time
function recalculateBookingPopup() {
    const selectPkg = document.getElementById('bookingPackageSelect');
    if (!selectPkg) return;

    const selectedOpt = selectPkg.options[selectPkg.selectedIndex];
    const pricePerDay = Number(selectedOpt ? selectedOpt.dataset.price : 125000);
    const pkgName = selectedOpt ? selectedOpt.dataset.name : 'Paket Penitipan';

    const checkInInput = document.getElementById('bookingCheckIn');
    const checkOutInput = document.getElementById('bookingCheckOut');

    let durationDays = 1;
    if (checkInInput && checkOutInput && checkInInput.value && checkOutInput.value) {
        const d1 = new Date(checkInInput.value);
        const d2 = new Date(checkOutInput.value);
        const diff = Math.round((d2 - d1) / (1000 * 60 * 60 * 24));
        if (diff >= 1) {
            durationDays = diff;
        } else {
            // Auto adjust checkout to at least +1 day
            const nextDay = new Date(d1.getTime() + 86400000);
            checkOutInput.value = nextDay.toISOString().split('T')[0];
            durationDays = 1;
        }
    }

    const roomSubtotal = pricePerDay * durationDays;

    // Calculate checked addons
    let addonsTotal = 0;
    document.querySelectorAll('.booking-addon-checkbox').forEach(cb => {
        const card = cb.closest('.addon-item');
        if (cb.checked) {
            if (card) card.classList.add('selected');
            const p = Number(cb.dataset.price || 0);
            const pType = cb.dataset.pricetype || 'flat';
            addonsTotal += (pType === 'per_day') ? (p * durationDays) : p;
        } else {
            if (card) card.classList.remove('selected');
        }
    });

    const adminFee = 5000;
    const grandTotal = roomSubtotal + addonsTotal + adminFee;

    // Update Summary DOM
    const elPkgLabel = document.getElementById('summaryPkgLabel');
    const elRoomSubtotal = document.getElementById('summaryRoomSubtotal');
    const elAddonsTotal = document.getElementById('summaryAddonsTotal');
    const elGrandTotal = document.getElementById('summaryGrandTotal');
    const elDurationBadge = document.getElementById('bookingDurationBadge');

    if (elPkgLabel) elPkgLabel.textContent = `${pkgName} (${formatIDR(pricePerDay)} × ${durationDays} hari)`;
    if (elRoomSubtotal) elRoomSubtotal.textContent = formatIDR(roomSubtotal);
    if (elAddonsTotal) elAddonsTotal.textContent = formatIDR(addonsTotal);
    if (elGrandTotal) elGrandTotal.textContent = formatIDR(grandTotal);
    if (elDurationBadge) elDurationBadge.textContent = `${durationDays} Hari / Malam`;
}

// Submit Booking Form via AJAX & Open Midtrans Snap Popup
async function submitBookingForm(e) {
    e.preventDefault();
    const form = e.target;
    const submitBtn = document.getElementById('btnSubmitBooking');
    const originalHtml = submitBtn.innerHTML;

    const selectedAddons = [];
    document.querySelectorAll('.booking-addon-checkbox:checked').forEach(cb => {
        selectedAddons.push(cb.value);
    });

    const payload = {
        package_id: document.getElementById('bookingPackageSelect').value,
        pet_name: document.getElementById('bookingPetName').value.trim(),
        pet_species: document.getElementById('bookingPetSpecies').value,
        pet_breed: document.getElementById('bookingPetBreed').value.trim(),
        pet_weight: document.getElementById('bookingPetWeight').value,
        pet_age: document.getElementById('bookingPetAge').value.trim(),
        check_in: document.getElementById('bookingCheckIn').value,
        check_out: document.getElementById('bookingCheckOut').value,
        pickup_option: document.getElementById('bookingPickupOption').value,
        special_notes: document.getElementById('bookingSpecialNotes').value.trim(),
        save_new_pet: document.getElementById('bookingSavePet') ? document.getElementById('bookingSavePet').checked : false,
        addons: selectedAddons
    };

    if (!payload.pet_name) {
        showToast('Mohon isi nama hewan peliharaan Anda.', 'error');
        return;
    }

    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menghubungkan Midtrans Snap...';

    try {
        const res = await fetch('api.php?action=create_booking', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();

        if (data.status === 'success') {
            closeBookingModal();
            showToast(data.message);
            currentActiveBooking = data.booking;

            // Open Midtrans Snap Payment Popup
            openMidtransSnapPopup(data.booking, data.snap);
        } else {
            showToast(data.message || 'Gagal membuat pesanan.', 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan koneksi ke server.', 'error');
    } finally {
        submitBtn.disabled = false;
        submitBtn.innerHTML = originalHtml;
    }
}

// ==========================================================================
// MIDTRANS SNAP PAYMENT GATEWAY POPUP (#midtransModal)
// ==========================================================================
function openMidtransSnapPopup(booking, snapData = null) {
    currentActiveBooking = booking;

    // If user configured Live Midtrans API and window.snap is loaded with a real token
    if (snapData && snapData.mode === 'live_api' && window.snap && typeof window.snap.pay === 'function') {
        window.snap.pay(snapData.snap_token, {
            onSuccess: function(result) {
                confirmMidtransPaymentServer(booking.id, 'settlement', result.payment_type || 'Midtrans Snap', result.va_numbers?.[0]?.va_number || '-');
            },
            onPending: function(result) {
                confirmMidtransPaymentServer(booking.id, 'pending', result.payment_type || 'Midtrans Pending', '-');
            },
            onError: function() {
                showToast('Pembayaran gagal diproses oleh Midtrans.', 'error');
            }
        });
        return;
    }

    // Open Interactive Midtrans Snap Popup
    document.getElementById('midtransOrderInvoice').textContent = booking.invoice_number;
    document.getElementById('midtransOrderTotal').textContent = formatIDR(booking.total_amount);
    document.getElementById('midtransSnapTokenText').textContent = booking.snap_token || 'SNAP-HP-TOKEN';
    document.getElementById('midtransItemSummary').textContent = `${booking.package_name} — ${booking.pet_name} (${booking.duration_days} Hari)`;

    // Select default channel (QRIS)
    selectMidtransChannel('QRIS (GoPay / M-Banking)', 'QRIS-HP-' + booking.id + '9981', 'qris', false);

    const modal = document.getElementById('midtransModal');
    if (modal) modal.classList.add('open');

    trackUserInteraction(
        'open_payment_modal',
        `Membuka popup pembayaran Midtrans untuk ${booking.invoice_number}`,
        'payment',
        booking.id,
        { invoice_number: booking.invoice_number, total_amount: booking.total_amount }
    );
}

function closeMidtransModal(reloadIfNew = true) {
    const modal = document.getElementById('midtransModal');
    if (modal) modal.classList.remove('open');
    if (reloadIfNew && currentActiveBooking) {
        window.location.href = 'index.php?tab=history';
    }
}

function selectMidtransChannel(channelName, vaCode, type, isUserClick = true) {
    selectedPaymentChannel = channelName;
    selectedVaNumber = vaCode;

    if (isUserClick && currentActiveBooking) {
        trackUserInteraction(
            'select_payment_channel',
            `Memilih metode pembayaran ${channelName} untuk ${currentActiveBooking.invoice_number}`,
            'payment',
            currentActiveBooking.id,
            { channel: channelName, type: type }
        );
    }

    document.querySelectorAll('.payment-channel-card').forEach(el => {
        el.classList.toggle('active', el.dataset.channel === channelName);
    });

    const instructionBox = document.getElementById('midtransInstructionBox');
    if (!instructionBox) return;

    if (type === 'qris') {
        instructionBox.innerHTML = `
            <div style="text-align:center; padding:14px; background:#F8FAFC; border:1px dashed #84A98C; border-radius:14px;">
                <div style="font-size:12px; font-weight:700; color:#354F52; margin-bottom:8px;">
                    SCAN QRIS MIDTRANS (GoPay, OVO, Dana, ShopeePay, BCA/Livin'/BRImo)
                </div>
                <div style="width:136px; height:136px; margin:0 auto 8px; background:#FFFFFF; border:2px solid #354F52; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-direction:column;">
                    <i class="fa-solid fa-qrcode" style="font-size:86px; color:#1E293B;"></i>
                </div>
                <div style="font-size:11.5px; color:#5A6B7C;">NMID: <code>ID1026008829102 (HAPPYPET CARE)</code></div>
            </div>
        `;
    } else if (type === 'card') {
        instructionBox.innerHTML = `
            <div style="padding:14px; background:#F8FAFC; border:1px solid #E2E8F0; border-radius:14px;">
                <div style="font-size:12px; font-weight:700; color:#354F52; margin-bottom:8px;">
                    Kartu Kredit / Debit Sandbox (3DS 2.0 Verified)
                </div>
                <div style="font-size:13px; color:#1E293B; font-family:monospace; background:#FFF; padding:9px 12px; border-radius:8px; border:1px solid #CBD5E1;">
                    4811-1111-1111-1114 &bull; EXP 08/29 &bull; CVV ***
                </div>
            </div>
        `;
    } else {
        instructionBox.innerHTML = `
            <div style="padding:14px; background:#F8FAFC; border:1px dashed #354F52; border-radius:14px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <div style="font-size:11.5px; color:#5A6B7C; font-weight:600;">Nomor Virtual Account (${channelName}):</div>
                    <div style="font-size:18px; font-weight:800; color:#354F52; letter-spacing:0.04em; font-family:monospace;">
                        ${vaCode}
                    </div>
                </div>
                <button type="button" class="btn btn-outline btn-sm" onclick="navigator.clipboard?.writeText('${vaCode}'); showToast('Nomor VA berhasil disalin!');">
                    <i class="fa-regular fa-copy"></i> Salin VA
                </button>
            </div>
        `;
    }
}

async function processMidtransSimulation(statusType = 'settlement') {
    if (!currentActiveBooking) return;
    await confirmMidtransPaymentServer(
        currentActiveBooking.id,
        statusType,
        statusType === 'settlement' ? selectedPaymentChannel : 'Menunggu Pembayaran (' + selectedPaymentChannel + ')',
        selectedVaNumber
    );
}

async function confirmMidtransPaymentServer(bookingId, paymentStatus, paymentChannel, vaNumber) {
    const btnPay = document.getElementById('btnMidtransConfirmPay');
    if (btnPay) {
        btnPay.disabled = true;
        btnPay.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Memverifikasi Pembayaran...';
    }

    try {
        const res = await fetch('api.php?action=midtrans_callback', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                booking_id: bookingId,
                payment_status: paymentStatus,
                payment_channel: paymentChannel,
                va_number: vaNumber
            })
        });
        const data = await res.json();
        if (data.status === 'success') {
            const modal = document.getElementById('midtransModal');
            if (modal) modal.classList.remove('open');
            showToast(data.message);

            if (paymentStatus === 'settlement') {
                // Open Receipt Preview Popup right away so user can inspect & print receipt!
                openReceiptPreviewModal(data.booking.id, true);
            } else {
                setTimeout(() => {
                    window.location.href = 'index.php?tab=history';
                }, 700);
            }
        } else {
            showToast(data.message || 'Gagal memperbarui status pembayaran.', 'error');
        }
    } catch (e) {
        showToast('Gagal menghubungi server pembayaran.', 'error');
    } finally {
        if (btnPay) {
            btnPay.disabled = false;
            btnPay.innerHTML = '<i class="fa-solid fa-shield-check"></i> Konfirmasi & Bayar Sekarang';
        }
    }
}

// Re-open Midtrans payment popup from Purchase History for a pending booking
async function payPendingBooking(bookingId) {
    try {
        const res = await fetch(`api.php?action=get_booking&id=${bookingId}`);
        const data = await res.json();
        if (data.status === 'success') {
            openMidtransSnapPopup(data.booking, null);
        } else {
            showToast(data.message || 'Data transaksi tidak ditemukan.', 'error');
        }
    } catch (err) {
        showToast('Gagal memuat data transaksi.', 'error');
    }
}

// ==========================================================================
// RECEIPT PREVIEW POPUP & PRINT (#receiptModal)
// ==========================================================================
let receiptModalShouldReloadOnClose = false;

async function openReceiptPreviewModal(bookingId, reloadOnClose = false) {
    receiptModalShouldReloadOnClose = reloadOnClose;
    try {
        const res = await fetch(`api.php?action=get_booking&id=${bookingId}`);
        const data = await res.json();
        if (data.status !== 'success') {
            showToast(data.message || 'Gagal memuat struk.', 'error');
            return;
        }

        const b = data.booking;
        const u = data.user;
        const isPaid = b.payment_status === 'settlement';

        let addonsRows = '';
        if (Array.isArray(b.addons) && b.addons.length > 0) {
            b.addons.forEach(ad => {
                addonsRows += `
                    <tr>
                        <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0;">
                            ${ad.name} <span style="font-size:11.5px; color:#64748B;">(Add-on)</span>
                        </td>
                        <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0; text-align:right; font-weight:600;">
                            ${formatIDR(ad.price)}
                        </td>
                    </tr>
                `;
            });
        }

        const contentEl = document.getElementById('receiptModalContent');
        contentEl.innerHTML = `
            <div style="border:1.5px solid #E6CCB2; border-radius:16px; padding:22px; background:#FFFFFF;">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid #84A98C; padding-bottom:14px; margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <div style="font-family:'Fraunces',serif; font-size:21px; font-weight:700; color:#354F52;">
                            <i class="fa-solid fa-paw" style="color:#84A98C;"></i> HappyPet Luxury Pet Care
                        </div>
                        <div style="font-size:12px; color:#5A6B7C;">Struk Resmi Penitipan & Perawatan Hewan</div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-family:monospace; font-weight:800; font-size:15px; color:#1E293B;">${b.invoice_number}</div>
                        <span class="status-badge ${isPaid ? 'status-settlement' : 'status-pending'}" style="margin-top:4px;">
                            <i class="fa-solid ${isPaid ? 'fa-circle-check' : 'fa-clock'}"></i>
                            ${isPaid ? 'LUNAS (MIDTRANS SETTLEMENT)' : 'MENUNGGU PEMBAYARAN'}
                        </span>
                    </div>
                </div>

                <div class="form-grid-2" style="background:#F8FAFC; padding:14px; border-radius:12px; margin-bottom:16px; font-size:13px;">
                    <div>
                        <div style="color:#64748B; font-size:11px; text-transform:uppercase; font-weight:700;">Pemilik & Kontak</div>
                        <div style="font-weight:700; color:#1E293B;">${u.name}</div>
                        <div style="color:#5A6B7C;">${u.phone || '-'} &bull; ${u.email}</div>
                    </div>
                    <div>
                        <div style="color:#64748B; font-size:11px; text-transform:uppercase; font-weight:700;">Hewan & Jadwal Menginap</div>
                        <div style="font-weight:700; color:#1E293B;">${b.pet_name} (${b.pet_species} - ${b.pet_breed})</div>
                        <div style="color:#5A6B7C;">Check-In: <strong>${b.check_in}</strong> s/d <strong>${b.check_out}</strong> (${b.duration_days} Hari)</div>
                    </div>
                </div>

                <table style="width:100%; border-collapse:collapse; font-size:13.5px; margin-bottom:14px;">
                    <tbody>
                        <tr>
                            <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0;">
                                <strong>${b.package_name}</strong> (${formatIDR(b.price_per_day)} × ${b.duration_days} hari)
                            </td>
                            <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0; text-align:right; font-weight:700;">
                                ${formatIDR(b.room_subtotal)}
                            </td>
                        </tr>
                        ${addonsRows}
                        <tr>
                            <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0;">
                                Biaya Layanan & Asuransi Kesehatan Hewan
                            </td>
                            <td style="padding:8px 0; border-bottom:1px dashed #E2E8F0; text-align:right; font-weight:600;">
                                ${formatIDR(b.admin_fee)}
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div style="background:#F7EFE6; padding:14px 18px; border-radius:12px; display:flex; justify-content:space-between; align-items:center; border:1px solid #E6CCB2;">
                    <div>
                        <div style="font-size:12px; color:#5A6B7C;">Metode: <strong>${b.payment_channel}</strong></div>
                        <div style="font-size:11.5px; color:#64748B;">Midtrans ID: <code>${b.midtrans_transaction_id || '-'}</code></div>
                    </div>
                    <div style="text-align:right;">
                        <div style="font-size:11.5px; color:#5A6B7C; font-weight:700;">TOTAL PEMBAYARAN</div>
                        <div style="font-size:20px; font-weight:800; color:#354F52;">${formatIDR(b.total_amount)}</div>
                    </div>
                </div>
            </div>
        `;

        document.getElementById('btnPrintFullReceipt').href = `struk.php?id=${b.id}&autoprint=1`;
        document.getElementById('btnViewFullReceipt').href = `struk.php?id=${b.id}`;

        const modal = document.getElementById('receiptModal');
        if (modal) modal.classList.add('open');
    } catch (e) {
        showToast('Gagal memuat detail struk.', 'error');
    }
}

function closeReceiptModal() {
    const modal = document.getElementById('receiptModal');
    if (modal) modal.classList.remove('open');
    if (receiptModalShouldReloadOnClose) {
        window.location.href = 'index.php?tab=history';
    }
}

// Filter Purchase History Table
function filterHistoryRows(status, btnEl) {
    document.querySelectorAll('.filter-pill').forEach(b => b.classList.remove('active'));
    if (btnEl) btnEl.classList.add('active');

    document.querySelectorAll('.history-row-item').forEach(row => {
        const rowStatus = row.getAttribute('data-status');
        if (status === 'all' || rowStatus === status) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });

    trackUserInteraction(
        'filter_history',
        `Memfilter riwayat transaksi berdasarkan status: ${status}`,
        'history',
        status,
        { filter_status: status }
    );
}

// Delete Booking History Item
window.deleteBookingItem = async function(bookingId, invoiceNumber = '') {
    const label = invoiceNumber ? ` (${invoiceNumber})` : '';
    if (!confirm(`Apakah Anda yakin ingin menghapus riwayat pemesanan${label} ini?`)) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('booking_id', bookingId);

        const res = await fetch('api.php?action=delete_booking', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message);
            // Animate and remove matching rows from DOM immediately
            const rows = document.querySelectorAll(`tr[data-booking-id="${bookingId}"]`);
            rows.forEach(r => {
                r.style.transition = 'all 0.3s ease';
                r.style.opacity = '0';
                r.style.transform = 'translateX(20px)';
                setTimeout(() => r.remove(), 300);
            });
            setTimeout(() => {
                window.location.reload();
            }, 600);
        } else {
            showToast(data.message || 'Gagal menghapus riwayat transaksi.', 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan saat menghapus riwayat.', 'error');
    }
};

// ==========================================================================
// EDIT PROFILE & PET MANAGEMENT
const AVATAR_MAP = {
    'cat-sage': { icon: 'fa-cat', bg: '#84A98C', label: 'Sage Cat' },
    'dog-amber': { icon: 'fa-dog', bg: '#D4A373', label: 'Amber Dog' },
    'paw-forest': { icon: 'fa-paw', bg: '#354F52', label: 'Forest Paw' },
    'dove-sand': { icon: 'fa-feather-pointed', bg: '#C59B76', label: 'Sand Feather' }
};

window.applyLiveUserAvatar = function(avKey, iconName = null, bgColor = null) {
    const meta = AVATAR_MAP[avKey] || AVATAR_MAP['cat-sage'];
    const resolvedIcon = iconName || meta.icon;
    const resolvedBg = bgColor || meta.bg;

    const badges = document.querySelectorAll(
        '.live-user-avatar, #sidebarUserAvatarBadge, .sidebar-user-card .user-avatar-badge, .user-avatar-badge'
    );
    badges.forEach(badge => {
        badge.style.background = resolvedBg;
        badge.style.setProperty('background', resolvedBg, 'important');
        const iconEl = badge.querySelector('i');
        if (iconEl) {
            iconEl.className = `fa-solid ${resolvedIcon}`;
        }
    });
};

window.handleAvatarSelect = function(labelEl) {
    document.querySelectorAll('.avatar-picker-item').forEach(el => el.classList.remove('active'));
    if (labelEl) {
        labelEl.classList.add('active');
        const radio = labelEl.querySelector('input[type="radio"]');
        if (radio) {
            radio.checked = true;
        }
    }
};

window.handleAvatarRadioChange = function(avKey) {
    document.querySelectorAll('.avatar-picker-item').forEach(el => {
        const inp = el.querySelector('input[type="radio"]');
        if (inp && inp.value === avKey) {
            el.classList.add('active');
        } else {
            el.classList.remove('active');
        }
    });
};

// ==========================================================================
// PROFILE & PET MANAGEMENT
// ==========================================================================
async function submitProfileUpdate(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSaveProfile');
    const orig = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Menyimpan...';

    const selectedAvatarEl = document.querySelector('input[name="profile_avatar"]:checked');
    const payload = {
        name: document.getElementById('profileName').value.trim(),
        email: document.getElementById('profileEmail').value.trim(),
        phone: document.getElementById('profilePhone').value.trim(),
        address: document.getElementById('profileAddress').value.trim(),
        emergency_contact: document.getElementById('profileEmergency').value.trim(),
        vet_notes: document.getElementById('profileVetNotes').value.trim(),
        avatar: selectedAvatarEl ? selectedAvatarEl.value : 'cat-sage',
        new_password: document.getElementById('profileNewPassword').value
    };

    try {
        const res = await fetch('api.php?action=update_profile', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message);
            
            // Perbarui nama di header & sidebar
            document.querySelectorAll('.live-user-name').forEach(el => {
                el.textContent = data.user.name;
            });
            document.getElementById('profileNewPassword').value = '';

            // Perbarui avatar di sidebar secara langsung
            const avKey = (data.user && data.user.avatar) ? data.user.avatar : (selectedAvatarEl ? selectedAvatarEl.value : 'cat-sage');
            const iconName = (data.user && data.user.avatar_icon) ? data.user.avatar_icon : null;
            const bgColor = (data.user && data.user.avatar_bg) ? data.user.avatar_bg : null;

            window.applyLiveUserAvatar(avKey, iconName, bgColor);

            // Perbarui status radio avatar di form profil
            document.querySelectorAll('.avatar-picker-item').forEach(el => {
                const inp = el.querySelector('input[name="profile_avatar"]');
                if (inp && inp.value === avKey) {
                    el.classList.add('active');
                    inp.checked = true;
                } else {
                    el.classList.remove('active');
                }
            });
        } else {
            showToast(data.message || 'Gagal memperbarui profil.', 'error');
        }
    } catch (err) {
        showToast('Terjadi kesalahan saat menyimpan profil.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = orig;
    }
}

async function submitAddPetForm(e) {
    e.preventDefault();
    const payload = {
        name: document.getElementById('newPetName').value.trim(),
        species: document.getElementById('newPetSpecies').value,
        breed: document.getElementById('newPetBreed').value.trim(),
        age: document.getElementById('newPetAge').value.trim(),
        weight: document.getElementById('newPetWeight').value,
        gender: document.getElementById('newPetGender').value,
        special_notes: document.getElementById('newPetNotes').value.trim(),
        vaccinated: true
    };

    try {
        const res = await fetch('api.php?action=save_pet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message);
            setTimeout(() => {
                window.location.href = 'index.php?tab=pets';
            }, 600);
        } else {
            showToast(data.message, 'error');
        }
    } catch (err) {
        showToast('Gagal menyimpan data hewan.', 'error');
    }
}

async function deletePetItem(petId) {
    if (!confirm('Hapus data hewan peliharaan ini dari daftar Anda?')) return;
    try {
        const res = await fetch('api.php?action=delete_pet', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ pet_id: petId })
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message);
            setTimeout(() => {
                window.location.href = 'index.php?tab=pets';
            }, 400);
        }
    } catch (err) {
        showToast('Gagal menghapus data hewan.', 'error');
    }
}

async function submitMidtransConfigForm(e) {
    e.preventDefault();
    const payload = {
        mode: document.getElementById('midtransModeSelect').value,
        environment: document.getElementById('midtransEnvSelect').value,
        client_key: document.getElementById('midtransClientKey').value.trim(),
        server_key: document.getElementById('midtransServerKey').value.trim()
    };
    try {
        const res = await fetch('api.php?action=save_midtrans_config', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (data.status === 'success') {
            showToast(data.message);
        }
    } catch (err) {
        showToast('Gagal menyimpan konfigurasi Midtrans.', 'error');
    }
}
