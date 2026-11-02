/**
 * توليد معرّفات العقد ونسخها.
 *
 * كان التوليد بثلاث طرق مختلفة في مكان واحد:
 *   - السحب والإفلات: الأكبر + 2  (يقفز، فيترك فجوات في التسلسل)
 *   - زر Duplicate:   عدد العقد + 1  (يمشي في تلك الفجوات)
 *
 * فتصطدمان: flow فيه start('1') ثم عقدة مسحوبة ('3')، وأول تكرار يعطي '3'
 * أيضًا. وعقدتان بنفس المعرّف تعنيان أن قاعدة «مخرج واحد لهدف واحد» تراهما
 * عقدة واحدة، فتوصيل النسخة يحذف وصلة الأصل — بلا سبب ظاهر للمستخدم.
 *
 * وكانت النسخة تشير إلى كائن `data` نفسه لا إلى نسخة منه، فتعديل إحداهما
 * يعدّل الأخرى.
 */

/**
 * أصغر معرّف رقمي غير مستعمل.
 *
 * المعرّفات غير الرقمية لا تُسقط الحساب (كانت `Math.max` ترجع NaN معها
 * فيصير معرّف كل عقدة جديدة "NaN")، لكنها تُحجز فلا يُعاد استعمالها.
 *
 * و«الأعلى + 1» وحده يُعيد معرّف آخر عقدة حُذفت، فيُكمل منها عميلٌ كان واقفًا
 * عليها (current_step) في عقدةٍ أخرى. floor آخر معرّف صدر في هذا الـ flow
 * (يُحفظ معه lastNodeId)، فلا يُعاد.
 *
 * @param {Array<{id: *}>} nodes عقد الـ flow الحالية
 * @param {number|string} floor آخر معرّف صدر
 * @returns {string} معرّف فريد
 */
export function nextNodeId(nodes, floor = 0) {
    const list = Array.isArray(nodes) ? nodes : []
    const taken = new Set(list.map((node) => String(node?.id)))

    let highest = 0

    for (const node of list) {
        const value = parseInt(String(node?.id), 10)

        if (Number.isFinite(value) && value > highest) {
            highest = value
        }
    }

    const issued = parseInt(String(floor), 10)
    let candidate = Math.max(highest, Number.isFinite(issued) ? issued : 0) + 1

    // الحلقة حارس لا أكثر: الفجوات وحدها لا تكفي بعد أن صار التوليد متّصلًا،
    // لكن flow محفوظًا من نسخة قديمة قد يحمل تكرارًا سابقًا.
    while (taken.has(String(candidate))) {
        candidate++
    }

    return String(candidate)
}

/** نسخة مستقلّة من بيانات العقدة — لا إشارة إلى الكائن نفسه. */
export function cloneNodeData(data) {
    if (data === null || typeof data !== 'object') {
        return data
    }

    try {
        return JSON.parse(JSON.stringify(data))
    } catch {
        return { ...data }
    }
}

/**
 * نسخة من عقدة: معرّف جديد، وإزاحة عن الأصل، وبيانات مستقلّة.
 *
 * @param {object} node العقدة المنسوخة
 * @param {Array<{id: *}>} nodes عقد الـ flow الحالية
 * @param {number} offset الإزاحة بالبكسل
 * @param {string|null} id المعرّف الجديد (من مولّد القماش)، وإلا التالي بعد الحالية
 */
export function duplicateNode(node, nodes, offset = 100, id = null) {
    const source = node ?? {}
    const position = source.position ?? {}

    return {
        id: id ?? nextNodeId(nodes),
        type: source.type,
        position: {
            x: (Number(position.x) || 0) + offset,
            y: (Number(position.y) || 0) + offset,
        },
        label: source.label,
        data: cloneNodeData(source.data),
    }
}
