const currentUserElement = document.getElementById('currentUser');
const logoutBtn = document.getElementById('logoutBtn');
const userNameInput = document.getElementById('userName');
const phoneInput = document.getElementById('phone');
const floorSelect = document.getElementById('floorSelect');
const dateInput = document.getElementById('dateInput');
const timeSlotsContainer = document.getElementById('timeSlotsContainer');
const loadSeatsBtn = document.getElementById('loadSeatsBtn');
const submitBookingBtn = document.getElementById('submitBookingBtn');
const messageBox = document.getElementById('messageBox');
const seatInfo = document.getElementById('seatInfo');
const seatGrid = document.getElementById('seatGrid');
const bookingList = document.getElementById('bookingList');
const seatTypeContainer = document.getElementById('seatTypeContainer');

let selectedSeatId = null;
let studentId = null;

function formatTimeSlot(slot) {
    const value = Number(slot);
    if (Number.isNaN(value)) return '未知';
    const start = String(value).padStart(2, '0');
    const end = String(value + 1).padStart(2, '0');
    return `${start}:00-${end}:00`;
}

function formatDate(value) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return value;
    return date.toISOString().slice(0, 10);
}

function showMessage(text, isError = true) {
    messageBox.innerText = text;
    messageBox.style.color = isError ? '#dc2626' : '#0f766e';
}

function clearMessage() {
    messageBox.innerText = '';
}

function redirectToLogin() {
    sessionStorage.removeItem('studentId');
    location.href = 'login.html';
}

function initPage() {
    studentId = sessionStorage.getItem('studentId');
    if (!studentId) {
        redirectToLogin();
        return;
    }
    currentUserElement.innerText = studentId;
    const today = new Date().toISOString().slice(0, 10);
    dateInput.value = today;
    dateInput.min = today;
    loadBookings();
    renderTimeSlots();
}

function renderTimeSlots() {
    // hours 6..21 represent hourly slots 06:00-07:00 ... 21:00-22:00
    for (let h = 6; h <= 21; h++) {
        const id = 'slot-' + h;
        const label = document.createElement('label');
        label.style.display = 'inline-flex';
        label.style.alignItems = 'center';
        label.style.gap = '6px';
        const chk = document.createElement('input');
        chk.type = 'checkbox';
        chk.value = String(h);
        chk.id = id;
        const span = document.createElement('span');
        span.innerText = h + ':00-' + (h+1) + ':00';
        label.appendChild(chk);
        label.appendChild(span);
        timeSlotsContainer.appendChild(label);
    }
}

function getSelectedTimeSlots() {
    const checks = timeSlotsContainer.querySelectorAll('input[type="checkbox"]');
    const sel = [];
    checks.forEach((c) => { if (c.checked) sel.push(c.value); });
    return sel;
}

function loadSeats() {
    clearMessage();
    selectedSeatId = null;
    seatGrid.innerHTML = '';
    seatInfo.innerText = '正在加载座位数据，请稍候...';

    const floor = floorSelect.value;
    const date = dateInput.value;
    const selectedSlots = getSelectedTimeSlots();

    if (!floor || !date || selectedSlots.length === 0) {
        showMessage('请选择楼层、日期和时间段');
        seatInfo.innerText = '请选择楼层、日期和时间段';
        return;
    }

    const timeParam = selectedSlots.join(',');

    fetch(`http://localhost:8080/api/seats?floor=${floor}&date=${date}&timeSlots=${encodeURIComponent(timeParam)}`)
        .then((response) => response.json())
        .then((data) => {
            if (!Array.isArray(data)) {
                showMessage('座位数据加载失败');
                seatInfo.innerText = '座位数据加载失败';
                return;
            }
            const availableSeats = data.filter((seat) => seat.available);
            const unavailableSeats = data.filter((seat) => !seat.available);
            if (availableSeats.length === 0) {
                seatInfo.innerText = '当前楼层暂无可预约座位，请更换楼层或时间段';
                seatTypeContainer.innerHTML = '';
                return;
            }
            seatInfo.innerText = `当前楼层共有 ${availableSeats.length} 个可预约座位，${unavailableSeats.length} 个已占用。`;

            // 分类别统计并显示类型选择按钮（按返回的 seatType 动态生成）
            const typeLabels = {
                1: '研习位',
                2: '大厅座位',
                3: '单人研习间',
                4: '四人研习舱',
                5: '群组讨论室'
            };
            const byType = {};
            availableSeats.forEach(s => {
                const t = s.seatType || 0;
                if (!byType[t]) byType[t] = [];
                byType[t].push(s);
            });
            seatTypeContainer.innerHTML = '';
            Object.keys(byType).map(Number).sort((a,b)=>a-b).forEach((typeId) => {
                const arr = byType[typeId];
                const labelText = typeLabels[typeId] || (`类型${typeId}`);
                const btn = document.createElement('button');
                btn.className = 'type-btn';
                btn.innerText = `${labelText} (${arr.length})`;
                btn.disabled = arr.length === 0;
                btn.addEventListener('click', () => {
                    seatTypeContainer.querySelectorAll('.type-btn').forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    renderSeatsByType(arr);
                });
                seatTypeContainer.appendChild(btn);
            });
            // 默认不自动展开，等待用户点击类型按钮
            seatGrid.innerHTML = '<div style="padding:12px;color:#444;">请选择座位类型后展开选择座位</div>';
        })
        .catch((error) => {
            console.error(error);
            showMessage('无法连接服务器，请稍后重试');
            seatInfo.innerText = '加载座位失败';
        });
}

function renderSeatsByType(availableSeats) {
    selectedSeatId = null;
    seatGrid.innerHTML = '';
    if (!Array.isArray(availableSeats) || availableSeats.length === 0) {
        seatGrid.innerHTML = '<div style="padding:12px;color:#444;">该类型暂无可预约座位</div>';
        return;
    }
    const visibleSeats = availableSeats.slice(0, 25);
    visibleSeats.forEach((seat) => {
        const card = document.createElement('div');
        card.className = 'seat-card available';
        card.innerHTML = `<strong>${seat.label}</strong><div>座位ID: ${seat.seatId}</div>`;
        card.addEventListener('click', () => {
            selectedSeatId = seat.seatId;
            Array.from(seatGrid.children).forEach((item) => item.classList.remove('selected'));
            card.classList.add('selected');
            clearMessage();
        });
        seatGrid.appendChild(card);
    });
    if (availableSeats.length > 25) {
        const remaining = availableSeats.length - 25;
        const moreCard = document.createElement('div');
        moreCard.className = 'seat-card toggle-card';
        moreCard.innerHTML = `<strong>还有 ${remaining} 个可预约座位</strong><div class="toggle-text">点击展开</div>`;
        moreCard.addEventListener('click', () => {
            moreCard.remove();
            availableSeats.slice(25).forEach((seat) => {
                const card = document.createElement('div');
                card.className = 'seat-card available';
                card.innerHTML = `<strong>${seat.label}</strong><div>座位ID: ${seat.seatId}</div>`;
                card.addEventListener('click', () => {
                    selectedSeatId = seat.seatId;
                    Array.from(seatGrid.children).forEach((item) => item.classList.remove('selected'));
                    card.classList.add('selected');
                    clearMessage();
                });
                seatGrid.appendChild(card);
            });
        });
        seatGrid.appendChild(moreCard);
    }
}

function loadBookings() {
    bookingList.innerHTML = '加载中...';
    fetch(`http://localhost:8080/api/bookings?userId=${encodeURIComponent(studentId)}`)
        .then((response) => response.json())
        .then((data) => {
            if (!Array.isArray(data)) {
                bookingList.innerText = '预约记录加载失败';
                return;
            }
            if (data.length === 0) {
                bookingList.innerText = '您还没有预约记录';
                return;
            }
            bookingList.innerHTML = '';
            data.forEach((item) => {
                const block = document.createElement('div');
                block.className = 'booking-item';
                block.innerHTML = `
                    <div><strong>预约日期：</strong>${item.bookingDate} ${formatTimeSlot(item.timeSlot)}</div>
                    <div><strong>楼层/座位：</strong>第 ${item.floor} 楼 - 座位 ${item.seatId}</div>
                    <div><strong>姓名：</strong>${item.userName}</div>
                    <div><strong>电话：</strong>${item.phone}</div>
                `;
                const btn = document.createElement('button');
                btn.className = 'cancel-btn';
                btn.innerText = '取消预约';
                btn.style.marginTop = '6px';
                btn.addEventListener('click', () => {
                    if (!confirm('确定要取消此预约吗？')) return;
                    cancelBooking(item.bookingId);
                });
                block.appendChild(btn);
                const editBtn = document.createElement('button');
                editBtn.className = 'edit-btn';
                editBtn.innerText = '修改';
                editBtn.style.marginLeft = '8px';
                editBtn.addEventListener('click', () => {
                    startEditBooking(block, item);
                });
                block.appendChild(editBtn);
                bookingList.appendChild(block);
            });
        })
        .catch((error) => {
            console.error(error);
            bookingList.innerText = '无法连接服务器，请稍后重试';
        });
}

function submitBooking() {
    clearMessage();
    const userName = userNameInput.value.trim();
    const phone = phoneInput.value.trim();
    const floor = floorSelect.value;
    const date = dateInput.value;
    const selectedSlots = getSelectedTimeSlots();

    if (userName === '') {
        showMessage('请输入姓名');
        return;
    }
    if (phone === '') {
        showMessage('请输入电话号码');
        return;
    }
    // 电话号码必须是 11 位数字（中国手机号格式）
    if (!/^\d{11}$/.test(phone)) {
        showMessage('请输入11位有效电话号码（仅数字）');
        return;
    }
    if (!selectedSeatId) {
        showMessage('请先选择一个可预约座位');
        return;
    }
    if (selectedSlots.length === 0) {
        showMessage('请选择至少一个时间段');
        return;
    }

    submitBookingBtn.disabled = true;
    submitBookingBtn.innerText = '预约中...';

    fetch('http://localhost:8080/api/booking', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            userName: userName,
            userId: studentId,
            phone: phone,
            seatId: `${selectedSeatId}`,
            date: date,
            timeSlots: selectedSlots.join(',')
        })
    })
    .then((response) => response.json())
    .then((data) => {
        submitBookingBtn.disabled = false;
        submitBookingBtn.innerText = '提交预约';
        if (data.success) {
            showMessage(data.message || '预约成功', false);
            selectedSeatId = null;
            loadSeats();
            loadBookings();
        } else {
            showMessage(data.message || '预约失败');
        }
    })
    .catch((error) => {
        console.error(error);
        submitBookingBtn.disabled = false;
        submitBookingBtn.innerText = '提交预约';
        showMessage('无法连接服务器，请稍后重试');
    });
}

function cancelBooking(bookingId) {
    if (!bookingId) return;
    fetch('http://localhost:8080/api/cancel', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ bookingId: String(bookingId), userId: studentId })
    })
    .then((res) => res.json())
    .then((data) => {
        if (data && data.success) {
            showMessage(data.message || '取消成功', false);
            loadBookings();
            // reload seats in case availability changed
            loadSeats();
        } else {
            showMessage(data.message || '取消失败');
        }
    })
    .catch((err) => {
        console.error(err);
        showMessage('无法连接服务器，取消失败');
    });
}

function startEditBooking(container, item) {
    // Prevent multiple editors
    if (container.querySelector('.edit-form')) return;
    const origHtml = container.innerHTML;
    const form = document.createElement('div');
    form.className = 'edit-form';

    const nameInput = document.createElement('input');
    nameInput.value = item.userName || '';
    nameInput.placeholder = '姓名';

    const phoneInputEdit = document.createElement('input');
    phoneInputEdit.value = item.phone || '';
    phoneInputEdit.placeholder = '手机号';

    const slotSelect = document.createElement('select');
    for (let h = 6; h <= 21; h++) {
        const opt = document.createElement('option');
        opt.value = String(h);
        opt.innerText = `${String(h).padStart(2,'0')}:00-${String(h+1).padStart(2,'0')}:00`;
        if (h === Number(item.timeSlot)) opt.selected = true;
        slotSelect.appendChild(opt);
    }

    const seatSelect = document.createElement('select');
    const seatOpt = document.createElement('option');
    seatOpt.value = String(item.seatId);
    seatOpt.innerText = `座位 ${item.seatId} (当前)`;
    seatSelect.appendChild(seatOpt);

    const loadBtn = document.createElement('button');
    loadBtn.innerText = '加载可用座位';
    loadBtn.addEventListener('click', () => {
        const floor = item.floor;
        const date = item.bookingDate;
        const slot = slotSelect.value;
        fetch(`http://localhost:8080/api/seats?floor=${floor}&date=${encodeURIComponent(date)}&timeSlots=${slot}`)
            .then((r) => r.json())
            .then((data) => {
                seatSelect.innerHTML = '';
                if (!Array.isArray(data)) return;
                data.forEach((s) => {
                    if (s.available) {
                        const o = document.createElement('option');
                        o.value = String(s.seatId);
                        o.innerText = `${s.label} (${s.seatId})`;
                        seatSelect.appendChild(o);
                    }
                });
                // ensure current seat is present
                if (![...seatSelect.options].some(o => o.value === String(item.seatId))) {
                    const cur = document.createElement('option');
                    cur.value = String(item.seatId);
                    cur.innerText = `当前座位 ${item.seatId}`;
                    seatSelect.appendChild(cur);
                }
            }).catch(() => {});
    });

    const saveBtn = document.createElement('button');
    saveBtn.innerText = '保存';
    saveBtn.addEventListener('click', () => {
        const newName = nameInput.value.trim();
        const newPhone = phoneInputEdit.value.trim();
        const newSlot = slotSelect.value;
        const newSeat = seatSelect.value;
        if (!newName) { showMessage('请输入姓名'); return; }
        if (!/^\d{11}$/.test(newPhone)) { showMessage('请输入11位手机号'); return; }
        fetch('http://localhost:8080/api/updateBooking', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                bookingId: String(item.bookingId),
                userId: studentId,
                userName: newName,
                phone: newPhone,
                seatId: String(newSeat),
                date: item.bookingDate,
                timeSlot: String(newSlot)
            })
        }).then(r => r.json())
        .then(data => {
            if (data && data.success) {
                showMessage(data.message || '修改成功', false);
                loadBookings();
                loadSeats();
            } else {
                showMessage(data.message || '修改失败');
            }
        }).catch((err) => { console.error(err); showMessage('无法连接服务器'); });
    });

    const cancelBtn = document.createElement('button');
    cancelBtn.innerText = '取消';
    cancelBtn.addEventListener('click', () => {
        container.innerHTML = origHtml;
        // re-render bookings list to re-bind handlers
        loadBookings();
    });

    form.appendChild(nameInput);
    form.appendChild(phoneInputEdit);
    form.appendChild(slotSelect);
    form.appendChild(loadBtn);
    form.appendChild(seatSelect);
    const actions = document.createElement('div');
    actions.className = 'edit-actions';
    actions.appendChild(saveBtn);
    actions.appendChild(cancelBtn);
    form.appendChild(actions);

    container.innerHTML = '';
    container.appendChild(form);
}

logoutBtn.addEventListener('click', redirectToLogin);
loadSeatsBtn.addEventListener('click', loadSeats);
submitBookingBtn.addEventListener('click', submitBooking);

initPage();
