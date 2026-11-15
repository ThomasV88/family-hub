import { computed, toValue } from 'vue';
import { useI18n } from 'vue-i18n';

// Copy of the delete confirmation for one health person or record. The server decides what a delete does: inside the delete
// window it is permanent, afterwards the record is archived. It sends that as the display-only hint `permanentDelete`
// (anything but an explicit false reads as permanent); the request is the same DELETE either way and the server decides again.
// typeKey is the lang group of the record: person, visit, medication, allergy, vaccination or doc.
export function useRemoval(typeKey, record) {
    const { t } = useI18n();
    const permanent = computed(() => toValue(record)?.permanentDelete !== false);
    return {
        permanent,
        action: computed(() => (permanent.value ? t(`health.${typeKey}.delete`) : t(`health.archive.title_${typeKey}`))),
        title: computed(() => (permanent.value ? t(`health.${typeKey}.delete_title`) : t(`health.archive.title_${typeKey}`))),
        body: computed(() => (permanent.value ? t(`health.${typeKey}.delete_body`) : t(`health.archive.body_${typeKey}`))),
    };
}
