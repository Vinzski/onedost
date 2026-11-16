// Photos are scaled down in the browser before they're sent: a phone's
// 12–48 megapixel photo is slow to upload and too big for a small server to
// decode. The server scales again (to 1600 px), so this only gets it close.

const MAX_SIDE = 1600;

// Small enough already: sent as is (the server reads its rotation hint).
const SMALL_BYTES = 1.5 * 1024 * 1024;

/** The photo as a JPEG at most MAX_SIDE on its longest side, upright; the original if the browser can't. */
export async function shrinkPhoto(file) {
    if (typeof createImageBitmap !== 'function') return file;
    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
        if (scale === 1 && file.size <= SMALL_BYTES) {
            bitmap.close();
            return file;
        }

        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        const context = canvas.getContext('2d');
        // A JPEG has no transparency: a PNG's clear parts become white, not black.
        context.fillStyle = '#fff';
        context.fillRect(0, 0, canvas.width, canvas.height);
        context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();

        const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', 0.85));
        if (!blob) return file;
        return new File([blob], file.name.replace(/\.[^.]+$/, '') + '.jpg', { type: 'image/jpeg', lastModified: file.lastModified });
    } catch {
        return file;
    }
}
