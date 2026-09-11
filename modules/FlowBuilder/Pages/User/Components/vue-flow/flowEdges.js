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
