/**
 * حذف وصلات مخرج واحد — مقصورًا على العقدة صاحبته.
 *
 * معرّفات المخارج ثابتة ومشتركة بين كل العقد من النوع نفسه: زر الرد الأول
 * دائمًا 'a' في كل عقدة Interactive Buttons، وصف القائمة الأول دائمًا 'a00'
 * في كل عقدة List. فالفلترة بـ sourceHandle وحده كانت تحذف وصلة الزر الأول
 * من **كل** العقد لا من العقدة التي طلبت الحذف.
 *
 * والنتيجة أن المستخدم يوصّل عقدة ثم يكتب حرفًا واحدًا في عقدة أخرى فتختفي
 * وصلته الأولى بلا سبب ظاهر — ثم تُحفظ محذوفة عند أول ضغطة Save.
 *
 * الحل: العقدة تحذف وصلات مخرجها هي فقط.
 */

/**
 * معرّفات الوصلات الخارجة من مخرج بعينه في عقدة بعينها.
 *
 * @param {Array<object>} edges وصلات الـ flow كلها
 * @param {string} nodeId العقدة صاحبة المخرج
 * @param {string} handleId معرّف المخرج داخل العقدة
 * @returns {Array<string>} معرّفات الوصلات المطلوب حذفها
 */
export function edgeIdsForHandle(edges, nodeId, handleId) {
    if (!Array.isArray(edges) || nodeId == null || handleId == null) {
        return []
    }

    return edges
        .filter((edge) => edge && edge.source === nodeId && edge.sourceHandle === handleId)
        .map((edge) => edge.id)
}

/**
 * معرّف الوصلة كما يولّده vue-flow: المصدر ومخرجه ثم الهدف ومدخله.
 */
export function edgeIdFor(edge, sourceHandle) {
    return `vueflow__edge-${edge.source}${sourceHandle ?? ''}-${edge.target}${edge.targetHandle ?? ''}`
}

/**
 * وصلات عقدة القائمة بعد حذف صفّ أو قسم.
 *
 * مخرج الصفّ موضعُه: 'a' + القسم + الصفّ، والخادم يبني المقبض من الموضع نفسه
 * حين يطابق ردّ العميل. فحذف «الدعم» من [المبيعات، الدعم، الفواتير] كان يُبقي
 * وصلة «الفواتير» على 'a02' الذي لم يعد موجودًا، فلا يصل من يختارها إلى شيء —
 * وأوّل صفّ جديد يأخذ 'a02' فيرث فرع «الفواتير».
 *
 * الآن تُحذف وصلات المحذوف، وتنزاح وصلات ما بعده خانةً لتتبع صفوفها.
 *
 * @param {Array<object>} edges وصلات الـ flow كلها
 * @param {string} nodeId عقدة القائمة
 * @param {{section: number, row?: number}} removed الصفّ المحذوف، أو القسم كله إن غاب row
 * @returns {Array<object>} الوصلات بعد الحذف والإزاحة
 */
export function remapListEdges(edges, nodeId, removed) {
    if (!Array.isArray(edges)) {
        return []
    }

    const removedSection = Number(removed?.section)
    const removedRow = removed?.row === undefined || removed?.row === null ? null : Number(removed.row)
    const result = []

    for (const edge of edges) {
        const match = edge && edge.source === nodeId && typeof edge.sourceHandle === 'string'
            ? /^a(\d)(\d)$/.exec(edge.sourceHandle)
            : null

        if (!match) {
            result.push(edge)
            continue
        }

        let section = Number(match[1])
        let row = Number(match[2])

        if (removedRow === null) {
            if (section === removedSection) continue
            if (section > removedSection) section--
        } else if (section === removedSection) {
            if (row === removedRow) continue
            if (row > removedRow) row--
        }

        const sourceHandle = `a${section}${row}`

        result.push(sourceHandle === edge.sourceHandle
            ? edge
            : { ...edge, sourceHandle, id: edgeIdFor(edge, sourceHandle) })
    }

    return result
}

/** عدد الوصلات الخارجة من العقدة — الداخلة إليها ليست من حصّة أزرارها. */
export function outgoingEdgeCount(edges, nodeId) {
    return Array.isArray(edges) ? edges.filter((edge) => edge && edge.source === nodeId).length : 0
}

/**
 * بصمة محتوى الـ flow لمعرفة التعديلات غير المحفوظة: العقد (المعرّف والنوع
 * والموضع والبيانات) والوصلات. الإزاحة والتكبير، والأبعاد والتحديد التي
 * يحسبها vue-flow، ليست تعديلًا. والمفاتيح مرتّبة: إعادة كتابة الكائن نفسه
 * بترتيبٍ آخر ليست تعديلًا أيضًا.
 */
export function flowSignature(json) {
    let graph

    try {
        graph = typeof json === 'string' ? JSON.parse(json) : json
    } catch {
        return String(json ?? '')
    }

    const nodes = Array.isArray(graph?.nodes) ? graph.nodes : []
    const edges = Array.isArray(graph?.edges) ? graph.edges : []

    return stableStringify({
        nodes: nodes.map((node) => [String(node?.id), node?.type, node?.position ?? null, node?.data ?? null]),
        edges: edges.map((edge) => [edge?.source, edge?.sourceHandle ?? null, edge?.target, edge?.targetHandle ?? null]),
    })
}

function stableStringify(value) {
    if (Array.isArray(value)) {
        return `[${value.map(stableStringify).join(',')}]`
    }

    if (value && typeof value === 'object') {
        return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${stableStringify(value[key])}`).join(',')}}`
    }

    return JSON.stringify(value ?? null)
}
