/* =========================================================
 *  split-editor.js — ตัวคำนวณการหารบิลในฟอร์ม (ใช้ทั้งหน้าเพิ่มและแก้ไข)
 *  ต้องการ element: #expenseForm, #total, .person-row > (.person-check, .amount-input),
 *                  #sumLabel, #cntPeople, #submitBtn, input[name="mode"]
 * ========================================================= */
(function () {
    const form = document.getElementById('expenseForm');
    if (!form) return;

    const totalEl = document.getElementById('total');
    const rows    = [...document.querySelectorAll('.person-row')];
    const sumLbl  = document.getElementById('sumLabel');
    const cntEl   = document.getElementById('cntPeople');
    const submit  = document.getElementById('submitBtn');

    const mode   = () => document.querySelector('input[name="mode"]:checked').value;
    const round2 = n => Math.round(n * 100) / 100;
    const checkedRows = () => rows.filter(r => r.querySelector('.person-check').checked);

    function recalc() {
        const total   = parseFloat(totalEl.value) || 0;
        const checked = checkedRows();
        const n       = checked.length;
        cntEl.textContent = n;

        const custom = mode() === 'custom';
        rows.forEach(r => {
            const on  = r.querySelector('.person-check').checked;
            const inp = r.querySelector('.amount-input');
            r.classList.toggle('opacity-40', !on);
            inp.readOnly = !custom || !on;
            inp.classList.toggle('bg-slate-50', inp.readOnly);
            if (!on) inp.value = '';
        });

        if (!custom) {
            // หารเท่ากัน: ปัดลง แล้วโยนเศษให้คนแรก
            if (n > 0 && total > 0) {
                const each = Math.floor(total / n * 100) / 100;
                const rem  = round2(total - each * n);
                checked.forEach((r, i) => {
                    r.querySelector('.amount-input').value = round2(each + (i === 0 ? rem : 0)).toFixed(2);
                });
            } else {
                checked.forEach(r => r.querySelector('.amount-input').value = '');
            }
        }

        let sum = 0;
        checked.forEach(r => sum += parseFloat(r.querySelector('.amount-input').value) || 0);
        sum = round2(sum);
        const diff = round2(total - sum);

        if (custom) {
            if (Math.abs(diff) < 0.01) {
                sumLbl.textContent = 'รวม ' + sum.toFixed(2) + ' ฿ ✓';
                sumLbl.className = 'text-emerald-600';
                submit.disabled = false; submit.classList.remove('opacity-50', 'cursor-not-allowed');
            } else {
                sumLbl.textContent = (diff > 0 ? 'ขาดอีก ' : 'เกินมา ') + Math.abs(diff).toFixed(2) + ' ฿';
                sumLbl.className = 'text-rose-500';
                submit.disabled = true; submit.classList.add('opacity-50', 'cursor-not-allowed');
            }
        } else {
            sumLbl.textContent = n > 0 ? ('คนละ ~' + (total / n || 0).toFixed(2) + ' ฿') : '';
            sumLbl.className = 'text-emerald-600';
            submit.disabled = false; submit.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    }

    form.addEventListener('input', recalc);
    form.addEventListener('change', recalc);
    recalc();
})();
