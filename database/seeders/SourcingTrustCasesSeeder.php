<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 客户案例改写为产业带口径。
 *
 * WHY：旧的 3 条已发布案例全部是阀门/法兰/泵内容，与重构后的 6 大产业带定位冲突，
 * 且 /case-studies 仍在对外展示。这里按 slug 原地改写（不删行、不改 id，
 * 避免 seo_meta / 外链失效），8 语言标题与正文同步更新。
 */
class SourcingTrustCasesSeeder extends Seeder
{
    private const CASES = [
        [
            'slug' => 'hamburg-water-project',
            'customer_name' => 'Hamburg Wasserwerke',
            'industry' => 'Consumer Electronics',
            'country' => 'DE',
            'title' => [
                'en' => '500 IoT Water-Monitoring Controllers for a Municipal Network',
                'es' => '500 controladores IoT de monitorización de agua para una red municipal',
                'ar' => '500 وحدات تحكم IoT لمراقبة المياه لشبكة بلدية',
                'ru' => '500 IoT-контроллеров мониторинга воды для муниципальной сети',
                'pt' => '500 controladores IoT de monitorização de água para uma rede municipal',
                'fr' => '500 contrôleurs IoT de suivi de l’eau pour un réseau municipal',
                'de' => '500 IoT-Wasserüberwachungscontroller für ein kommunales Netz',
                'it' => '500 controller IoT per il monitoraggio dell’acqua di una rete comunale',
            ],
            'body' => [
                'en' => '<p>Hamburg Wasserwerke sourced 500 IoT water-metering controllers from our Shenzhen factory for its 2025 municipal network expansion. All units passed AQL 2.5 inspection, carried CE and RoHS documentation, and were delivered within 45 days FOB Hamburg.</p><p>Key results: zero field failures over 12 months in service and a 15% cost saving versus the previous European supplier.</p>',
                'es' => '<p>Hamburg Wasserwerke-gap acquired 500 controladores IoT de medición de agua a nuestra fábrica de Shenzhen para la ampliación de su red municipal de 2025. Todas las unidades superaron una inspección AQL 2.5, con documentación CE y RoHS, y se entregaron en 45 días FOB Hamburgo.</p><p>Resultados clave: cero averías en 12 meses de servicio y un ahorro del 15% frente al proveedor europeo anterior.</p>',
                'ar' => '<p>استخدمت Hamburg Wasserwerke وحدة تحكم IoT واحدة وعشرين لقياس المياه من مصنعنا في شنتشن لتوسيع شبكتها البلدية لعام 2025. اجتازت جميع الوحدات فحص AQL 2.5 وحملت شهادات CE وRoHS، وسُلّمت خلال 45 يومًا FOB هامبورغ.</p><p>النتائج الرئيسية: لا أعطال ميدانية خلال 12 شهرًا من الخدمة وتوفير 15% في التكلفة مقارنة بالمورد الأوروبي السابق.</p>',
                'ru' => '<p>Hamburg Wasserwerke заказали 500 IoT-контроллеров учёта воды на нашей шэньчжэньской фабрике для расширения муниципальной сети в 2025 году. Все изделия прошли приёмку по AQL 2.5, снабжены сертификатами CE и RoHS, отгружены за 45 дней FOB Гамбург.</p><p>Ключевые результаты: ни одного отказа в эксплуатации за 12 месяцев и экономия 15% по сравнению с прежним европейским поставщиком.</p>',
                'pt' => '<p>A Hamburg Wasserwerke comprou 500 controladores IoT de medição de água à nossa fábrica de Shenzhen para a expansão da sua rede municipal em 2025. Todas as unidades passaram numa inspeção AQL 2.5, com documentação CE e RoHS, e foram entregues em 45 dias FOB Hamburgo.</p><p>Principais resultados: zero falhas em 12 meses de operação e uma poupança de 15% face ao fornecedor europeu anterior.</p>',
                'fr' => '<p>Hamburg Wasserwerke a commandé 500 contrôleurs IoT de comptage d’eau auprès de notre usine de Shenzhen pour l’extension de son réseau municipal en 2025. Toutes les unités ont passé un contrôle AQL 2.5, étaient accompagnées des documents CE et RoHS, et ont été livrées en 45 jours FOB Hambourg.</p><p>Résultats clés : aucune défaillance sur le terrain pendant 12 mois et une économie de 15 % par rapport au précédent fournisseur européen.</p>',
                'de' => '<p>Hamburg Wasserwerke bezog 500 IoT-Wasserzählcontroller für den Ausbau seines kommunalen Netzes 2025 aus unserem Werk in Shenzhen. Alle Geräte bestanden die AQL-2.5-Prüfung, lagen mit CE- und RoHS-Unterlagen vor und wurden innerhalb von 45 Tagen FOB Hamburg geliefert.</p><p>Wichtigste Ergebnisse: keine Ausfälle im 12-monatigen Betrieb und 15 % Kostenvorteil gegenüber dem bisherigen europäischen Lieferanten.</p>',
                'it' => '<p>Hamburg Wasserwerke ha acquistato 500 controller IoT per la misurazione dell’acqua dalla nostra fabbrica di Shenzhen per l’ampliamento della sua rete comunale nel 2025. Tutte le unità hanno superato l’ispezione AQL 2.5, disponevano della documentazione CE e RoHS e sono state consegnate in 45 giorni FOB Amburgo.</p><p>Risultati principali: zero guasti sul campo in 12 mesi di servizio e un risparmio del 15% rispetto al precedente fornitore europeo.</p>',
            ],
        ],
        [
            'slug' => 'dubai-marina-hvac',
            'customer_name' => 'Marina Facility Management',
            'industry' => 'LED Commercial Lighting',
            'country' => 'AE',
            'title' => [
                'en' => '2,400 High-Bay LED Fixtures for 12 High-Rise Towers',
                'es' => '2.400 luminarias LED High-Bay para 12 torres de gran altura',
                'ar' => '2400 وحدة إضاءة LED عالية الجودة لـ 12 برجًا شاهقًا',
                'ru' => '2400 промышленных светильников LED High-Bay для 12 высотных башен',
                'pt' => '2.400 luminárias LED High-Bay para 12 torres de grande altura',
                'fr' => '2 400 luminaires LED High-Bay pour 12 tours de grande hauteur',
                'de' => '2.400 High-Bay-LED-Leuchten für 12 Hochhäuser',
                'it' => '2.400 lampade LED High-Bay per 12 torri di grande altezza',
            ],
            'body' => [
                'en' => '<p>Marina Facility Management standardized on our Zhongshan-made 150W high-bay LED fixtures across 12 high-rise towers in Dubai Marina. 2,400 units were delivered over two phases with local commissioning support.</p><p>Energy consumption dropped 18% versus the legacy lighting fleet, and every batch carried LM-79 test reports.</p>',
                'es' => '<p>Marina Facility Management estandarizó nuestras luminarias LED High-Bay de 150 W fabricadas en Zhongshan en 12 torres de Dubái Marina. Se entregaron 2.400 unidades en dos fases con soporte local de puesta en marcha.</p><p>El consumo energético bajó un 18% frente a la iluminación anterior, y cada lote incluía informes de ensayo LM-79.</p>',
                'ar' => '<p>اعتمدت Marina Facility Management على مصابيح LED High-Bay بقدرة 150 واط الصادرة من مصنعنا في تشونغشان عبر 12 برجًا في دبي مارينا. تم تسليم 2400 وحدة على مرحلتين مع دعم محلي للتشغيل.</p><p>انخفض استهلاك الطاقة بنسبة 18% مقارنة بإضاءة الأسطول القديم، وحملت كل دفعة تقارير اختبار LM-79.</p>',
                'ru' => '<p>Marina Facility Management перешла на наши промышленные светильники LED High-Bay мощностью 150 Вт с завода в Чжуншане на 12 высотных башнях комплекса Дубай-Марина. Всего 2 400 светильников поставлены двумя партиями с местным шефмонтажем.</p><p>Потребление энергии снизилось на 18% по сравнению со старым освещением, каждый комплект сопровождался протоколами испытаний LM-79.</p>',
                'pt' => '<p>A Marina Facility Management padronizou as nossas luminárias LED High-Bay de 150 W fabricadas em Zhongshan em 12 torres do Dubai Marina. Foram entregues 2.400 unidades em duas fases, com suporte local de comissionamento.</p><p>O consumo de energia caiu 18% face ao parque de iluminação anterior, e cada lote contou com relatórios de ensaio LM-79.</p>',
                'fr' => '<p>Marina Facility Management a standardisé nos luminaires LED High-Bay 150 W fabriqués à Zhongshan sur 12 tours de Dubai Marina. 2 400 unités ont été livrées en deux phases, avec un support local de mise en service.</p><p>La consommation d’énergie a diminué de 18 % par rapport à l’ancien parc d’éclairage, et chaque lot était accompagné de rapports d’essai LM-79.</p>',
                'de' => '<p>Marina Facility Management standardisierte in Dubai Marina unsere 150-W-High-Bay-LED-Leuchten aus dem Werk in Zhongshan für 12 Hochhäuser. Ausgeliefert wurden 2.400 Leuchten in zwei Etappen mit lokaler Inbetriebnahmehilfe.</p><p>Der Energieverbrauch sank um 18 % gegenüber dem alten Leuchtenbestand, jede Charge enthielt LM-79-Prüfberichte.</p>',
                'it' => '<p>Marina Facility Management ha standardizzato sui nostri lampade LED High-Bay da 150 W prodotte a Zhongshan in 12 torri del Dubai Marina. Sono state consegnate 2.400 unità in due fasi con supporto locale di messa in servizio.</p><p>Il consumo energetico è sceso del 18% rispetto al vecchio parco luci e ogni lotto ha incluso i rapporti di prova LM-79.</p>',
            ],
        ],
        [
            'slug' => 'santos-port-piping',
            'customer_name' => 'Porto de Santos',
            'industry' => 'Packaging & Printing',
            'country' => 'BR',
            'title' => [
                'en' => 'Custom Corrugated Export Boxes for a Port Expansion',
                'es' => 'Cajas de cartón corrugado personalizadas para la ampliación portuaria',
                'ar' => 'صناديق تصدير مخصصة من الكرتون المضلع لتوسعة الميناء',
                'ru' => 'Фирменные гофрокоробки для экспорта при расширении порта',
                'pt' => 'Caixas de cartão canelado personalizadas para a expansão portuária',
                'fr' => 'Caisses en carton ondulé sur mesure pour l’extension portuaire',
                'de' => 'Maßgefertigte Wellpappe-Exportboxen für den Hafenausbau',
                'it' => 'Scatole personalizzate in cartone ondulato per l’ampliamento del porto',
            ],
            'body' => [
                'en' => '<p>Porto de Santos, the largest port in Latin America, sourced 2400 custom corrugated shipping boxes from our Cangnan packaging plant for its expansion project. FSC-certified board, soy-based inks and BSCI-audited production were required for every batch.</p><p>Three shipments completed on schedule over 90 days, with flat-pack design cutting warehouse volume by 31%.</p>',
                'es' => '<p>Porto de Santos, el mayor puerto de América Latina, adquirió 2400 cajas de cartón corrugado personalizadas a nuestra planta de embalaje de Cangnan para su proyecto de ampliación. Se exigía para cada lote cartón con certificación FSC, tintas a base de soja y producción auditada por BSCI.</p><p>Tres envíos se completaron según lo previsto en 90 días, y el diseño plegado redujo un 31% el volumen de almacén.</p>',
                'ar' => '<p>اشترى ميناء سانتوس، أكبر ميناء في أمريكا اللاتينية، 2400 صندوق تصدير مخصص من مصنع التغليف لدينا في تشانغنان لمشروع التوسعة. طُلب لكل دفعة كرتون حاصل على شهادة FSC وحبر قائم على فول الصويا وإنتاج معتمد من BSCI.</p><p>اكتملت ثلاثة شحنات في الموعد خلال 90 يومًا، وقلّل التصميم القابل للطي حجم المستودع بنسبة 31%.</p>',
                'ru' => '<p>Порт Сантуса — крупнейший порт Латинской Америки — заказал 2400 фирменных гофрокоробок на нашей упаковочной фабрике в Каннане для проекта расширения. Для каждой партии требовались картон с сертификатом FSC, краски на соевой основе и аудированное по BSCI производство.</p><p>Три отгрузки выполнены в срок за 90 дней, а складчатый дизайн сократил складские объёмы на 31%.</p>',
                'pt' => '<p>O Porto de Santos, maior porto da América Latina, comprou 2400 caixas de cartão canelado personalizadas à nossa fábrica de embalagens de Cangnan para a sua expansão. Para cada lote exigiram-se cartão com certificação FSC, tintas à base de soja e produção auditada pela BSCI.</p><p>Três expedições foram concluídas no prazo em 90 dias, e o design flat-pack reduziu 31% do volume de armazém.</p>',
                'fr' => '<p>Porto de Santos, le plus grand port d’Amérique latine, a commandé 2 400 caisses en carton ondulé sur mesure à notre usine d’emballage de Cangnan pour son projet d’extension. Carton certifié FSC, encres à base de soja et production auditée BSCI étaient exigés pour chaque lot.</p><p>Trois expéditions ont été réalisées dans les délais en 90 jours, et le design plat a réduit de 31 % le volume d’entreposage.</p>',
                'de' => '<p>Der Hafen Santos, der größte Hafen Lateinamerikas, bezog 2400 maßgefertigte Wellpappe-Transportboxen aus unserer Verpackungsfabrik in Cangnan für sein Ausbauprojekt. FSC-zertifizierter Karton, Soj Druckfarben und BSCI-auditiertes Produktionsverfahren waren für jede Charge vorgeschrieben.</p><p>Drei Sendungen wurden innerhalb von 90 Tagen termingerecht abgeschlossen, das Flachpackdesign senkte das Lagervolumen um 31 %.</p>',
                'it' => '<p>Il Porto di Santos, il maggiore porto dell’America Latina, ha acquistato 2400 scatole personalizzate in cartone ondulato dal nostro stabilimento di imballaggio a Cangnan per il suo progetto di ampliamento. Per ogni lotto erano richiesti cartone certificato FSC, inchiostri a base di soia e produzione sottoposta ad audit BSCI.</p><p>Tre spedizioni sono state completate nei termini in 90 giorni e il design flat-pack ha ridotto del 31% il volume di magazzino.</p>',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::CASES as $case) {
            DB::table('trust_cases')->where('slug', $case['slug'])->update([
                'customer_name' => $case['customer_name'],
                'industry' => $case['industry'],
                'country' => $case['country'],
                'title' => json_encode($case['title'], JSON_UNESCAPED_UNICODE),
                'body' => json_encode($case['body'], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]);
        }
    }
}
