/**
 * ═══════════════════════════════════════════════════════════════════
 *  BIRD'S NEST POS - PRODUCT IMAGE CROPPER MODULE
 *  Interactive image cropping with 1:1 Aspect Ratio, Zoom, Rotate, Flip
 * ═══════════════════════════════════════════════════════════════════
 */

(function() {
    let cropperInstance = null;
    let currentCallback = null;
    let cropperModal = null;
    let targetInput = null;
    let targetPreview = null;

    // Load Cropper.js CSS & JS dynamically if not yet in DOM
    function ensureCropperLoaded(callback) {
        if (!document.getElementById('cropper-css')) {
            const link = document.createElement('link');
            link.id = 'cropper-css';
            link.rel = 'stylesheet';
            link.href = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.css';
            document.head.appendChild(link);
        }

        if (typeof Cropper !== 'undefined') {
            callback();
            return;
        }

        if (!document.getElementById('cropper-js')) {
            const script = document.createElement('script');
            script.id = 'cropper-js';
            script.src = 'https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.1/cropper.min.js';
            script.onload = callback;
            script.onerror = function() {
                console.error("Failed to load Cropper.js from CDN.");
            };
            document.head.appendChild(script);
        } else {
            const checkInt = setInterval(() => {
                if (typeof Cropper !== 'undefined') {
                    clearInterval(checkInt);
                    callback();
                }
            }, 50);
        }
    }

    // Create Cropper Modal DOM
    function createModalDOM() {
        if (document.getElementById('productCropperModal')) {
            cropperModal = document.getElementById('productCropperModal');
            return;
        }

        const modal = document.createElement('div');
        modal.id = 'productCropperModal';
        modal.innerHTML = `
            <div class="pcm-card">
                <div class="pcm-header">
                    <div class="pcm-title">
                        <i class="fa-solid fa-crop-simple"></i>
                        <span>កាត់តម្រឹមរូបភាព (Crop Product Image)</span>
                    </div>
                    <button type="button" class="pcm-close" id="pcmCloseBtn" title="Close">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <div class="pcm-body">
                    <div class="pcm-cropper-area">
                        <img id="pcmImageToCrop" src="" alt="Crop image">
                    </div>
                    
                    <div class="pcm-toolbar">
                        <!-- Aspect Ratio Buttons -->
                        <div class="pcm-ratio-group">
                            <button type="button" class="pcm-ratio-btn active" data-ratio="1">1:1 (Square)</button>
                            <button type="button" class="pcm-ratio-btn" data-ratio="1.333">4:3</button>
                            <button type="button" class="pcm-ratio-btn" data-ratio="1.777">16:9</button>
                            <button type="button" class="pcm-ratio-btn" data-ratio="NaN">Free</button>
                        </div>

                        <!-- Tool Actions (Rotate, Flip, Zoom) -->
                        <div class="pcm-tools-row">
                            <div class="pcm-zoom-wrap">
                                <button type="button" class="pcm-btn-tool" id="pcmZoomOutBtn" title="Zoom Out"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                                <input type="range" class="pcm-zoom-slider" id="pcmZoomSlider" min="0.1" max="3" step="0.05" value="1">
                                <button type="button" class="pcm-btn-tool" id="pcmZoomInBtn" title="Zoom In"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                            </div>
                            <button type="button" class="pcm-btn-tool" id="pcmRotateLeftBtn" title="Rotate -90°"><i class="fa-solid fa-rotate-left"></i> -90°</button>
                            <button type="button" class="pcm-btn-tool" id="pcmRotateRightBtn" title="Rotate +90°"><i class="fa-solid fa-rotate-right"></i> +90°</button>
                            <button type="button" class="pcm-btn-tool" id="pcmFlipXBtn" title="Flip Horizontal"><i class="fa-solid fa-arrows-left-right"></i> Flip</button>
                            <button type="button" class="pcm-btn-tool" id="pcmResetBtn" title="Reset"><i class="fa-solid fa-arrows-rotate"></i> Reset</button>
                        </div>
                    </div>
                </div>
                <div class="pcm-footer">
                    <button type="button" class="pcm-btn-cancel" id="pcmCancelBtn">បោះបង់ (Cancel)</button>
                    <button type="button" class="pcm-btn-apply" id="pcmApplyBtn">
                        <i class="fa-solid fa-check"></i>
                        <span>កាត់ & យល់ព្រម (Crop & Apply)</span>
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);
        cropperModal = modal;

        // Hook up toolbar listeners
        document.getElementById('pcmCloseBtn').addEventListener('click', closeCropperModal);
        document.getElementById('pcmCancelBtn').addEventListener('click', closeCropperModal);
        document.getElementById('pcmApplyBtn').addEventListener('click', applyCroppedImage);

        // Aspect ratio switcher
        modal.querySelectorAll('.pcm-ratio-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                modal.querySelectorAll('.pcm-ratio-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');
                const ratio = parseFloat(this.dataset.ratio);
                if (cropperInstance) {
                    cropperInstance.setAspectRatio(isNaN(ratio) ? NaN : ratio);
                }
            });
        });

        // Rotate & Flip
        let flipX = 1;
        document.getElementById('pcmRotateLeftBtn').addEventListener('click', () => cropperInstance && cropperInstance.rotate(-90));
        document.getElementById('pcmRotateRightBtn').addEventListener('click', () => cropperInstance && cropperInstance.rotate(90));
        document.getElementById('pcmFlipXBtn').addEventListener('click', () => {
            if (!cropperInstance) return;
            flipX = -flipX;
            cropperInstance.scaleX(flipX);
        });
        document.getElementById('pcmResetBtn').addEventListener('click', () => {
            if (!cropperInstance) return;
            flipX = 1;
            cropperInstance.reset();
            document.getElementById('pcmZoomSlider').value = 1;
        });

        // Zoom Slider & Buttons
        const zoomSlider = document.getElementById('pcmZoomSlider');
        zoomSlider.addEventListener('input', function() {
            if (cropperInstance) {
                cropperInstance.zoomTo(parseFloat(this.value));
            }
        });
        document.getElementById('pcmZoomInBtn').addEventListener('click', () => {
            if (cropperInstance) {
                cropperInstance.zoom(0.1);
                zoomSlider.value = (parseFloat(zoomSlider.value) + 0.1).toFixed(2);
            }
        });
        document.getElementById('pcmZoomOutBtn').addEventListener('click', () => {
            if (cropperInstance) {
                cropperInstance.zoom(-0.1);
                zoomSlider.value = Math.max(0.1, parseFloat(zoomSlider.value) - 0.1).toFixed(2);
            }
        });
    }

    function openCropperModal(imageSrc, onCropDone, defaultRatio = 1) {
        ensureCropperLoaded(() => {
            createModalDOM();
            const imgEl = document.getElementById('pcmImageToCrop');
            imgEl.src = imageSrc;

            cropperModal.classList.add('active');

            if (cropperInstance) {
                cropperInstance.destroy();
                cropperInstance = null;
            }

            // Reset ratio buttons to 1:1 default
            cropperModal.querySelectorAll('.pcm-ratio-btn').forEach(b => {
                b.classList.toggle('active', b.dataset.ratio == defaultRatio);
            });

            cropperInstance = new Cropper(imgEl, {
                aspectRatio: defaultRatio,
                viewMode: 2, // Restrict the crop box to not exceed the size of the canvas
                dragMode: 'move',
                autoCropArea: 0.9,
                restore: false,
                guides: true,
                center: true,
                highlight: false,
                cropBoxMovable: true,
                cropBoxResizable: true,
                toggleDragModeOnDblclick: false,
                ready: function() {
                    document.getElementById('pcmZoomSlider').value = 1;
                }
            });

            currentCallback = onCropDone;
        });
    }

    function closeCropperModal() {
        if (cropperModal) {
            cropperModal.classList.remove('active');
        }
        if (cropperInstance) {
            cropperInstance.destroy();
            cropperInstance = null;
        }
        currentCallback = null;
    }

    function applyCroppedImage() {
        if (!cropperInstance) return;

        const applyBtn = document.getElementById('pcmApplyBtn');
        const origText = applyBtn.innerHTML;
        applyBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
        applyBtn.disabled = true;

        // Generate high resolution square crop (up to 800x800 for crystal clear product photos)
        const canvas = cropperInstance.getCroppedCanvas({
            width: 800,
            height: 800,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });

        if (!canvas) {
            applyBtn.innerHTML = origText;
            applyBtn.disabled = false;
            closeCropperModal();
            return;
        }

        canvas.toBlob(blob => {
            const dataUrl = canvas.toDataURL('image/png', 0.92);
            const fileName = 'cropped_product_' + Date.now() + '.png';
            const croppedFile = new File([blob], fileName, { type: 'image/png' });

            if (typeof currentCallback === 'function') {
                currentCallback(blob, dataUrl, croppedFile);
            }

            applyBtn.innerHTML = origText;
            applyBtn.disabled = false;
            closeCropperModal();

            if (typeof showToast === 'function') {
                showToast('Image cropped successfully!', 'success');
            }
        }, 'image/png', 0.92);
    }

    /**
     * Bind cropper to an input and preview element
     */
    function attachProductCropper(fileInput, previewImg, options = {}) {
        if (!fileInput) return;

        fileInput.addEventListener('change', function(e) {
            const file = this.files && this.files[0];
            if (!file) return;

            // Prevent re-triggering when we programmatically assign the cropped file
            if (file._isCropped) return;

            const reader = new FileReader();
            reader.onload = function(ev) {
                openCropperModal(ev.target.result, function(blob, dataUrl, croppedFile) {
                    croppedFile._isCropped = true;

                    // Assign cropped file to target input using DataTransfer
                    try {
                        const dt = new DataTransfer();
                        dt.items.add(croppedFile);
                        fileInput.files = dt.files;
                    } catch (err) {
                        console.warn("DataTransfer not supported, using fallback preview", err);
                    }

                    // Update preview image
                    if (previewImg) {
                        previewImg.src = dataUrl;
                        previewImg.style.display = 'block';
                    }

                    if (typeof options.onSuccess === 'function') {
                        options.onSuccess(croppedFile, dataUrl);
                    }
                }, options.aspectRatio || 1);
            };
            reader.readAsDataURL(file);
        });
    }

    // Expose functions globally
    window.openProductCropper = openCropperModal;
    window.closeProductCropper = closeCropperModal;
    window.attachProductCropper = attachProductCropper;

    // Auto-bind on DOM ready for known product upload inputs
    document.addEventListener('DOMContentLoaded', function() {
        // 1. edit_product.php
        const epInput1 = document.getElementById('imgInput');
        const epPreview1 = document.getElementById('imgPreview');
        if (epInput1) {
            attachProductCropper(epInput1, epPreview1, {
                onSuccess: function(file, dataUrl) {
                    const fn = document.getElementById('fileName');
                    if (fn) fn.innerHTML = `<span>${file.name}</span> (Cropped) — ${(file.size/1024).toFixed(1)} KB`;
                }
            });
        }

        // 2. products.php modal (epImageInput)
        const epInput2 = document.getElementById('epImageInput');
        const epPreview2 = document.getElementById('epImgPreview');
        if (epInput2) {
            attachProductCropper(epInput2, epPreview2);
        }

        // 3. add_product.php (f_img_input)
        const epInput3 = document.getElementById('f_img_input');
        const epPreview3 = document.getElementById('f_img_preview');
        if (epInput3) {
            attachProductCropper(epInput3, epPreview3, {
                onSuccess: function(file, dataUrl) {
                    const st = document.getElementById('f_img_status');
                    if (st) st.textContent = file.name + ' (Cropped)';
                }
            });
        }
    });
})();
