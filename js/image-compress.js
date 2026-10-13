/* Veedo — compresie imagini client-side (canvas).
   Reduce pozele inainte de upload: redimensionare + recompresie JPEG.
   Functioneaza fara GD pe server. Daca ceva esueaza, se intoarce fisierul original. */
(function () {
  'use strict';

  function compress(file, opts) {
    opts = opts || {};
    var maxDim  = opts.maxDim  || 1600;   // latura maxima (px)
    var quality = opts.quality || 0.82;   // calitate JPEG 0–1

    return new Promise(function (resolve) {
      // sarim peste ce nu e imagine sau e GIF (poate fi animat)
      if (!file || !file.type || file.type.indexOf('image/') !== 0 || file.type === 'image/gif') {
        return resolve(file);
      }

      var url, img = new Image();
      var done = false;
      function finish(result) { if (!done) { done = true; resolve(result); } }

      try { url = URL.createObjectURL(file); } catch (e) { return finish(file); }

      img.onerror = function () { try { URL.revokeObjectURL(url); } catch (e) {} finish(file); };
      img.onload  = function () {
        try { URL.revokeObjectURL(url); } catch (e) {}
        var w = img.naturalWidth, h = img.naturalHeight;
        if (!w || !h) return finish(file);

        var scale = Math.min(1, maxDim / Math.max(w, h));
        var nw = Math.max(1, Math.round(w * scale));
        var nh = Math.max(1, Math.round(h * scale));

        var canvas = document.createElement('canvas');
        canvas.width = nw; canvas.height = nh;
        var ctx = canvas.getContext('2d');
        if (!ctx || !canvas.toBlob) return finish(file);

        // fundal alb — transparenta PNG -> alb (nu negru) la conversia in JPEG
        ctx.fillStyle = '#ffffff';
        ctx.fillRect(0, 0, nw, nh);
        try { ctx.drawImage(img, 0, 0, nw, nh); }
        catch (e) { return finish(file); }

        canvas.toBlob(function (blob) {
          // daca nu am castigat nimic, pastram originalul
          if (!blob || blob.size >= file.size) return finish(file);
          var base = (file.name || 'imagine').replace(/\.[a-z0-9]+$/i, '');
          try {
            var out = new File([blob], base + '.jpg', { type: 'image/jpeg', lastModified: Date.now() });
            finish(out);
          } catch (e) {
            // browsere vechi fara constructorul File
            try { blob.name = base + '.jpg'; } catch (e2) {}
            finish(blob);
          }
        }, 'image/jpeg', quality);
      };
      img.src = url;
    });
  }

  function compressMany(files, opts) {
    return Promise.all(Array.prototype.map.call(files, function (f) {
      return compress(f, opts);
    }));
  }

  window.VeedoCompress = { compress: compress, compressMany: compressMany };
})();
