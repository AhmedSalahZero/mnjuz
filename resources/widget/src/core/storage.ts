/**
 * ما يحفظه الـ widget في متصفّح الزائر: توكنه، ومعرّفه، وآخر ما رآه.
 *
 * localStorage قد يُرفض (تصفّح خاصّ، حظر بيانات المواقع) أو يرمي، فكل
 * وصول ملفوف — والـ widget يعمل بلا حفظ، فقط يبدأ زائراً جديداً كل مرّة.
 */
export interface Persisted {
    token?: string
    visitorId?: string
    name?: string
    channel?: string | null
    lastSeq?: number
    lastReadSeq?: number
}

export interface KeyValueStore {
    getItem(key: string): string | null
    setItem(key: string, value: string): void
    removeItem(key: string): void
}

export function browserStore(): KeyValueStore | null {
    try {
        const store = globalThis.localStorage
        const probe = '__mnjz_probe__'
        store.setItem(probe, '1')
        store.removeItem(probe)

        return store
    } catch {
        return null
    }
}

export function createStorage(widgetKey: string, backend: KeyValueStore | null = browserStore()) {
    const key = `mnjz:${widgetKey}`
    let memory: Persisted = {}

    const read = (): Persisted => {
        if (!backend) {
            return { ...memory }
        }

        try {
            const value = JSON.parse(backend.getItem(key) || '{}')

            return value && typeof value === 'object' ? value : {}
        } catch {
            return {}
        }
    }

    return {
        key,
        read,
        write(patch: Persisted): Persisted {
            const next = { ...read(), ...patch }
            memory = next

            try {
                backend?.setItem(key, JSON.stringify(next))
            } catch {
                // المساحة ممتلئة أو محجوبة: يبقى في الذاكرة لهذه الصفحة.
            }

            return next
        },
        clear(): void {
            memory = {}

            try {
                backend?.removeItem(key)
            } catch {
                // لا شيء.
            }
        },
        /** قيمة جانبية بمفتاح مشتقّ (قفل التاب القائد، إشارة المزامنة). */
        raw(suffix: string): { get(): string | null; set(value: string): void } {
            const full = `${key}:${suffix}`

            return {
                get: () => {
                    try {
                        return backend?.getItem(full) ?? null
                    } catch {
                        return null
                    }
                },
                set: (value: string) => {
                    try {
                        backend?.setItem(full, value)
                    } catch {
                        // لا شيء.
                    }
                },
            }
        },
    }
}

export type WidgetStorage = ReturnType<typeof createStorage>
