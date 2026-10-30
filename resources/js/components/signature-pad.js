/**
 * Signature pads: a canvas with data-signature-pad draws what is traced on it (mouse, finger or pen) and keeps the
 * drawing as a PNG data URL in the input named by data-signature-target (a wire:model input), so the form can send
 * it. A button with data-signature-clear="<canvas id>" wipes the pad.
 */
const LINE_WIDTH = 2.2;

const prepare = (canvas) => {
    if (canvas.dataset.ready) {
        return;
    }

    const ratio = Math.max(window.devicePixelRatio || 1, 1);
    const box = canvas.getBoundingClientRect();
    canvas.width = Math.round(box.width * ratio);
    canvas.height = Math.round(box.height * ratio);

    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, box.width, box.height);
    context.lineWidth = LINE_WIDTH;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#000000';
    canvas.dataset.ready = '1';
};

const save = (canvas) => {
    const input = document.getElementById(canvas.dataset.signatureTarget);

    if (input) {
        input.value = canvas.dataset.dirty ? canvas.toDataURL('image/png') : '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
};

export function initSignaturePads() {
    let drawing = null;

    const point = (canvas, event) => {
        const box = canvas.getBoundingClientRect();

        return [event.clientX - box.left, event.clientY - box.top];
    };

    document.addEventListener('pointerdown', (event) => {
        const canvas = event.target.closest?.('canvas[data-signature-pad]');

        if (!canvas) {
            return;
        }

        prepare(canvas);
        canvas.setPointerCapture(event.pointerId);
        const context = canvas.getContext('2d');
        const [x, y] = point(canvas, event);
        context.beginPath();
        context.moveTo(x, y);
        drawing = { canvas, moved: false };
        event.preventDefault();
    });

    document.addEventListener('pointermove', (event) => {
        if (!drawing) {
            return;
        }

        const [x, y] = point(drawing.canvas, event);
        const context = drawing.canvas.getContext('2d');
        context.lineTo(x, y);
        context.stroke();
        drawing.moved = true;
    });

    const finish = () => {
        if (!drawing) {
            return;
        }

        if (drawing.moved) {
            drawing.canvas.dataset.dirty = '1';
            save(drawing.canvas);
        }

        drawing = null;
    };

    document.addEventListener('pointerup', finish);
    document.addEventListener('pointercancel', finish);

    document.addEventListener('click', (event) => {
        const button = event.target.closest?.('[data-signature-clear]');

        if (!button) {
            return;
        }

        const canvas = document.getElementById(button.dataset.signatureClear);

        if (canvas) {
            delete canvas.dataset.ready;
            delete canvas.dataset.dirty;
            prepare(canvas);
            save(canvas);
        }
    });
}
