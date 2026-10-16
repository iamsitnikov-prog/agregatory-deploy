"""Шифрует шаблоны договоров паролем страницы /dokumenty.

Репозиторий выкладки открытый, поэтому шаблоны лежат в нём только в
зашифрованном виде (AES-256-GCM, ключ из пароля через PBKDF2-SHA256).
Расшифровывает браузер бухгалтера после ввода пароля.

  python3 encrypt.py <пароль> <вход.docx> <выход.enc>
Формат файла: 'AGD1' | соль 16 байт | nonce 12 байт | шифртекст с тегом.
"""
import os
import sys
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
from cryptography.hazmat.primitives import hashes

ITER = 200_000


def key_from(password: str, salt: bytes) -> bytes:
    return PBKDF2HMAC(algorithm=hashes.SHA256(), length=32, salt=salt, iterations=ITER).derive(password.encode())


def main():
    pw, src, dst = sys.argv[1:4]
    salt, nonce = os.urandom(16), os.urandom(12)
    data = open(src, 'rb').read()
    ct = AESGCM(key_from(pw, salt)).encrypt(nonce, data, b'agregatory-docs')
    open(dst, 'wb').write(b'AGD1' + salt + nonce + ct)
    # проверка
    raw = open(dst, 'rb').read()
    assert AESGCM(key_from(pw, raw[4:20])).decrypt(raw[20:32], raw[32:], b'agregatory-docs') == data
    print(dst, len(raw))


if __name__ == '__main__':
    main()
