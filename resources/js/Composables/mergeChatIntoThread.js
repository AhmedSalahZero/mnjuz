/**
 * دمج رسالة واردة من البثّ في شريط المحادثة.
 *
 * الرسالة الواحدة تصل أكثر من مرّة: بثّ عند الإرسال يحمل معرّفنا المؤقّت،
 * ثم بثّ لكل تغيّر حالة (sent/delivered/read) يحمل wam_id مكان المعرّف
 * المؤقّت. وصولها بترتيب غير متوقّع كان يُنتج فقاعات مكرّرة — ملفّان مرفوعان
 * يظهران ثلاثة حتى إعادة تحميل الصفحة.
 *
 * فُصلت دالّةً نقيّة لتُختبر بلا Vue: المنطق هنا هو موضع العلّة، لا العرض.
 *
 * @param {Array} thread شريط المحادثة (يُعدَّل في مكانه)
 * @param {Array} chat   الحمولة المبثوثة: [{ type, value, tempMessageId? }]
 * @returns {{ merged: boolean, appended: boolean }}
 */
export function mergeChatIntoThread(thread, chat) {
	const entry = chat?.[0]
	if (!entry?.value || entry.value.deleted_at != null) {
		return { merged: false, appended: false }
	}

	const wamId = entry.value.wam_id

	// الفقاعة المتفائلة تُخزَّن بـwam_id = المعرّف المؤقّت.
	const tempIndex = entry.tempMessageId
		? thread.findIndex((item) => item?.[0]?.value?.wam_id === entry.tempMessageId)
		: -1

	const realIndex = wamId
		? thread.findIndex((item) => item?.[0]?.value?.wam_id === wamId)
		: -1

	// الرسالة موجودة بالفعل: نُحدّثها ونُسقط أي فقاعة مؤقّتة باقية لها.
	// بدون هذا الإسقاط تبقى الفقاعتان معاً — وهو أصل التكرار.
	if (realIndex !== -1) {
		thread[realIndex] = chat
		if (tempIndex !== -1 && tempIndex !== realIndex) {
			thread.splice(tempIndex, 1)
		}
		return { merged: true, appended: false }
	}

	if (tempIndex !== -1) {
		thread[tempIndex] = chat
		return { merged: true, appended: false }
	}

	thread.push(chat)
	return { merged: true, appended: true }
}
