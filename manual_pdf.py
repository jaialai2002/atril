#!/usr/bin/env python3
"""Imprime el manual de una obra del Atril a PDF con Chrome sin interfaz.

Uso:  python3 manual_pdf.py <id-obra> <salida.pdf> [--web https://atril.sigan.me/] [--chrome RUTA]

Abre <web>?manual#<id-obra>, espera a que la página marque <html data-manual="listo">
(partitura dibujada, mezclador copiado, QR hecho) y guarda el PDF A4.
Solo usa la biblioteca estándar de Python: habla con Chrome por --remote-debugging-pipe.
La obra tiene que estar ya subida a la web (obras.json + su carpeta).
"""
import argparse, base64, json, os, shutil, subprocess, sys, tempfile, time

MAC_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'


def buscar_chrome(ruta):
    for c in [ruta, MAC_CHROME, shutil.which('google-chrome'), shutil.which('chromium'), shutil.which('chrome')]:
        if c and os.path.exists(c):
            return c
    sys.exit('No encuentro Google Chrome')


class Chrome:
    def __init__(self, exe):
        self.perfil = tempfile.mkdtemp(prefix='atril-manual-')
        a_chrome = os.pipe()   # nosotros escribimos, Chrome lee (fd 3)
        de_chrome = os.pipe()  # Chrome escribe (fd 4), nosotros leemos
        def hijo():   # en el hijo, el canal de órdenes tiene que quedar en los fds 3 (entrada) y 4 (salida)
            x, y = os.dup(a_chrome[0]), os.dup(de_chrome[1])
            os.dup2(x, 3); os.dup2(y, 4)
        extra = ['--no-sandbox'] if hasattr(os, 'geteuid') and os.geteuid() == 0 else []
        self.p = subprocess.Popen(
            [exe, '--headless=new', '--remote-debugging-pipe', '--no-first-run', '--no-default-browser-check',
             '--disable-gpu', '--hide-scrollbars', '--mute-audio', '--user-data-dir=' + self.perfil] + extra + ['about:blank'],
            close_fds=False, preexec_fn=hijo,
            stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        os.close(a_chrome[0]); os.close(de_chrome[1])
        self.w = os.fdopen(a_chrome[1], 'wb', buffering=0); self.r = os.fdopen(de_chrome[0], 'rb', buffering=0)
        self.n = 0; self.buf = b''

    def _leer(self):
        while b'\0' not in self.buf:
            trozo = self.r.read(65536)
            if not trozo:
                raise RuntimeError('Chrome se ha cerrado')
            self.buf += trozo
        msg, self.buf = self.buf.split(b'\0', 1)
        return json.loads(msg)

    def orden(self, metodo, params=None, sesion=None):
        self.n += 1; n = self.n
        m = {'id': n, 'method': metodo, 'params': params or {}}
        if sesion: m['sessionId'] = sesion
        self.w.write(json.dumps(m).encode() + b'\0')
        while True:
            r = self._leer()
            if r.get('id') == n:
                if 'error' in r: raise RuntimeError(metodo + ': ' + r['error'].get('message', ''))
                return r.get('result', {})

    def cerrar(self):
        try: self.orden('Browser.close')
        except Exception: pass
        try: self.p.wait(5)
        except Exception: self.p.kill()
        shutil.rmtree(self.perfil, ignore_errors=True)


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n')[0])
    ap.add_argument('obra'); ap.add_argument('salida')
    ap.add_argument('--web', default='https://atril.sigan.me/')
    ap.add_argument('--chrome', default=None)
    ap.add_argument('--espera', type=int, default=120, help='segundos como máximo')
    a = ap.parse_args()
    url = a.web.rstrip('/') + '/?manual#' + a.obra
    c = Chrome(buscar_chrome(a.chrome))
    try:
        t = c.orden('Target.createTarget', {'url': 'about:blank'})['targetId']
        s = c.orden('Target.attachToTarget', {'targetId': t, 'flatten': True})['sessionId']
        c.orden('Emulation.setDeviceMetricsOverride', {'width': 1100, 'height': 900, 'deviceScaleFactor': 1, 'mobile': False}, s)
        c.orden('Page.navigate', {'url': url}, s)
        fin = time.time() + a.espera
        while True:
            v = c.orden('Runtime.evaluate', {'expression': "[document.documentElement.dataset.manual||'', (document.querySelector('#cargando .error, .error')||{}).textContent||'']", 'returnByValue': True}, s)
            estado, error = v.get('result', {}).get('value', ['', ''])
            if estado == 'listo': break
            if time.time() > fin:
                sys.exit('El manual no ha terminado de prepararse (' + url + ')' + (': ' + error if error else ''))
            time.sleep(0.5)
        pdf = c.orden('Page.printToPDF', {'printBackground': True, 'preferCSSPageSize': True,
                                         'displayHeaderFooter': False, 'paperWidth': 8.27, 'paperHeight': 11.69,
                                         'marginTop': 0, 'marginBottom': 0, 'marginLeft': 0, 'marginRight': 0}, s)
        with open(a.salida, 'wb') as f: f.write(base64.b64decode(pdf['data']))
        print(a.salida)
    finally:
        c.cerrar()


if __name__ == '__main__':
    main()
