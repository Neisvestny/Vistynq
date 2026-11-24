/**
 * Проверка фактического формата файла по «магическим байтам».
 * Расширение и MIME от браузера легко подделать — перед загрузкой аватара
 * или вложения сверяем заголовок файла с объявленным типом.
 */
export const IMAGE_MIME_TYPES = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'] as const

const SIGNATURES: ReadonlyArray<{ mime: string; matches: (head: Uint8Array) => boolean }> = [
  {
    mime: 'image/png',
    matches: (head) =>
      head.length >= 8 &&
      [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a].every((byte, i) => head[i] === byte),
  },
  {
    mime: 'image/jpeg',
    matches: (head) => head.length >= 3 && head[0] === 0xff && head[1] === 0xd8 && head[2] === 0xff,
  },
  {
    mime: 'image/gif',
    matches: (head) =>
      head.length >= 4 && String.fromCharCode(...head.subarray(0, 4)) === 'GIF8',
  },
  {
    mime: 'image/webp',
    matches: (head) =>
      head.length >= 12 &&
      String.fromCharCode(...head.subarray(0, 4)) === 'RIFF' &&
      String.fromCharCode(...head.subarray(8, 12)) === 'WEBP',
  },
]

/** Возвращает MIME, соответствующий содержимому файла, или null. */
export async function sniffImageMime(file: Blob): Promise<string | null> {
  if (typeof file.arrayBuffer !== 'function') return null
  const buffer = await file.arrayBuffer()
  const head = new Uint8Array(buffer.slice(0, 12))
  return SIGNATURES.find((signature) => signature.matches(head))?.mime ?? null
}

/**
 * Проверяет, что объявленный IMAGE MIME совпадает с реальным содержимым.
 * Возвращает true, если тип не является поддерживаемым изображением
 * (такой файл будет отклонён списком разрешённых типов).
 */
export async function matchesImageMime(file: Blob, declaredMime: string): Promise<boolean> {
  if (!(IMAGE_MIME_TYPES as readonly string[]).includes(declaredMime)) return true
  const actual = await sniffImageMime(file)
  return actual === declaredMime
}
