/* =========================================================
 *  image-compress.js — บีบขนาดรูปฝั่ง browser ก่อนอัปโหลด
 *  attachImageCompressor(inputEl, { maxDim, quality, onPreview })
 *  - ย่อด้านยาวสุดไม่เกิน maxDim แล้ว export เป็น JPEG คุณภาพ quality
 *  - แทนไฟล์ใน input ด้วยไฟล์ที่บีบแล้ว (ฟอร์มจะส่งไฟล์เล็กลง)
 * ========================================================= */
window.attachImageCompressor = function (input, opts) {
    opts = opts || {};
    const maxDim    = opts.maxDim  || 1280;
    const quality   = opts.quality || 0.7;
    const onPreview = opts.onPreview || function () {};

    input.addEventListener('change', async function () {
        const file = input.files && input.files[0];
        if (!file) return;
        if (!file.type || !file.type.startsWith('image/')) { onPreview(URL.createObjectURL(file)); return; }
        try {
            const out = await compress(file, maxDim, quality);
            // แทนไฟล์เดิมใน input ด้วยไฟล์ที่บีบแล้ว
            const dt = new DataTransfer();
            dt.items.add(out);
            input.files = dt.files;
            onPreview(URL.createObjectURL(out));
        } catch (e) {
            // บีบไม่ได้ (เช่น browser เก่า/HEIC) — ใช้ไฟล์เดิม
            onPreview(URL.createObjectURL(file));
        }
    });

    function compress(file, maxDim, quality) {
        return new Promise(function (resolve, reject) {
            const img = new Image();
            img.onload = function () {
                let w = img.naturalWidth, h = img.naturalHeight;
                if (w > maxDim || h > maxDim) {
                    if (w >= h) { h = Math.round(h * maxDim / w); w = maxDim; }
                    else        { w = Math.round(w * maxDim / h); h = maxDim; }
                }
                const canvas = document.createElement('canvas');
                canvas.width = w; canvas.height = h;
                canvas.getContext('2d').drawImage(img, 0, 0, w, h);
                canvas.toBlob(function (blob) {
                    if (!blob) { reject(new Error('toBlob failed')); return; }
                    const base = (file.name || 'receipt').replace(/\.[^.]+$/, '');
                    resolve(new File([blob], base + '.jpg', { type: 'image/jpeg' }));
                }, 'image/jpeg', quality);
            };
            img.onerror = reject;
            img.src = URL.createObjectURL(file);
        });
    }
};
