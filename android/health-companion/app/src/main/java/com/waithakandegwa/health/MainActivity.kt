package com.waithakandegwa.health

import android.os.Bundle
import android.content.Intent
import android.net.Uri
import android.widget.*
import androidx.activity.ComponentActivity
import androidx.activity.result.contract.ActivityResultContracts
import androidx.health.connect.client.aggregate.AggregateMetric
import androidx.health.connect.client.HealthConnectClient
import androidx.health.connect.client.PermissionController
import androidx.health.connect.client.permission.HealthPermission
import androidx.health.connect.client.records.ExerciseSessionRecord
import androidx.health.connect.client.records.DistanceRecord
import androidx.health.connect.client.records.StepsRecord
import androidx.health.connect.client.records.metadata.DataOrigin
import androidx.health.connect.client.request.AggregateRequest
import androidx.health.connect.client.request.ReadRecordsRequest
import androidx.health.connect.client.time.TimeRangeFilter
import androidx.lifecycle.lifecycleScope
import kotlinx.coroutines.launch
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.withContext
import java.time.Instant
import java.time.temporal.ChronoUnit

/** User-triggered file transfer only. No server credentials, telemetry or network permission. */
class MainActivity : ComponentActivity() {
    private lateinit var status: TextView
    private lateinit var source: Spinner
    private lateinit var days: Spinner
    private lateinit var consent: CheckBox
    private var prepared: String? = null
    private var busy = false
    private val core = setOf(HealthPermission.getReadPermission(ExerciseSessionRecord::class))
    private val metrics = setOf(HealthPermission.getReadPermission(DistanceRecord::class), HealthPermission.getReadPermission(StepsRecord::class))
    private val permissions = registerForActivityResult(PermissionController.createRequestPermissionResultContract()) { granted ->
        status.text = if (granted.containsAll(core)) "Workout permission granted. Optional measurements may be absent. Preview the export next." else "Workout permission was not granted. No activity file was created."
    }
    private val save = registerForActivityResult(ActivityResultContracts.CreateDocument("text/csv")) { uri ->
        val csv = prepared
        if (uri == null || csv == null) { status.text = "Save cancelled. Nothing was uploaded."; return@registerForActivityResult }
        lifecycleScope.launch {
            try {
                if(!HealthConnectClient.getOrCreate(this@MainActivity).permissionController.getGrantedPermissions().containsAll(core)) error("Permission revoked")
                withContext(Dispatchers.IO) { contentResolver.openOutputStream(uri)?.use { it.write(csv.toByteArray(Charsets.UTF_8)) } ?: error("No file destination") }
                prepared = null
                status.text = "CSV saved to your selected destination. Open your protected Writer Activity workspace, upload it, review the preview and confirm. No upload was performed by this app."
            } catch (_: Exception) { status.text = "The CSV could not be saved. Review the selected destination and try again." }
        }
    }
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        val pad = (20 * resources.displayMetrics.density).toInt()
        val layout = LinearLayout(this).apply { orientation = LinearLayout.VERTICAL; setPadding(pad,pad,pad,pad) }
        fun text(value: String, size: Float = 16f) = TextView(this).apply { text=value; textSize=size; setPadding(0,10,0,12); layout.addView(this) }
        text("Waithaka Activity", 25f)
        text("Test companion · private, manual transfer")
        text("Read your selected workout summaries from Android Health Connect. No GPS, heart rate, sleep or clinical data. This app has no internet permission, sends nothing automatically and stores no activity files internally. A file saved to cloud storage uses the destination you choose.")
        text("Source app")
        source=Spinner(this).apply { adapter=ArrayAdapter(this@MainActivity,android.R.layout.simple_spinner_dropdown_item,listOf("Samsung Health", "Google Fit", "All permitted source apps")); layout.addView(this) }
        text("Recent period (Health Connect permission history limits apply)")
        days=Spinner(this).apply { adapter=ArrayAdapter(this@MainActivity,android.R.layout.simple_spinner_dropdown_item,listOf("7 days", "30 days")); layout.addView(this) }
        consent=CheckBox(this).apply { text="I want to read and save these workout summaries for my private Writer reports."; layout.addView(this) }
        fun button(label: String, action: () -> Unit) { layout.addView(Button(this).apply { text=label; setOnClickListener { action() } }) }
        button("1. Review Health Connect permissions") {
            if (!consent.isChecked) { status.text="Review and select the consent checkbox first."; return@button }
            when (HealthConnectClient.getSdkStatus(this)) {
                HealthConnectClient.SDK_AVAILABLE -> permissions.launch(core + metrics)
                HealthConnectClient.SDK_UNAVAILABLE_PROVIDER_UPDATE_REQUIRED -> { status.text="Install or update Health Connect from Google Play, then return."; startActivity(Intent(Intent.ACTION_VIEW,Uri.parse("https://play.google.com/store/apps/details?id=com.google.android.apps.healthdata"))) }
                else -> status.text="Health Connect is unavailable on this device. No file was created."
            }
        }
        button("2. Preview selected workout summaries") { preview() }
        button("3. Save reviewed CSV") {
            if (!consent.isChecked || prepared == null) { status.text="Preview the selected activities and review the summary first."; return@button }
            save.launch("waithaka-activities.csv")
        }
        button("Health Connect permission settings") {
            try { startActivity(Intent(HealthConnectClient.ACTION_HEALTH_CONNECT_SETTINGS)) } catch (_: Exception) { status.text="Open Health Connect in Android Settings to manage or revoke access." }
        }
        status=text("No activity data has been read. Grant only the permissions you choose.")
        text("Permission rationale: workout times/types identify sessions; optional distance and step reads provide session measurements. You can deny measurements and still export sessions. Missing metrics stay blank. User-selected CSV files are not automatically deleted when the app is removed; delete them from the storage destination yourself.")
        setContentView(ScrollView(this).apply { fitsSystemWindows=true; addView(layout) })
        source.onItemSelectedListener=object: android.widget.AdapterView.OnItemSelectedListener { override fun onItemSelected(p:android.widget.AdapterView<*>?,v:android.view.View?,position:Int,id:Long){prepared=null}; override fun onNothingSelected(p:android.widget.AdapterView<*>?){} }
        days.onItemSelectedListener=object: android.widget.AdapterView.OnItemSelectedListener { override fun onItemSelected(p:android.widget.AdapterView<*>?,v:android.view.View?,position:Int,id:Long){prepared=null}; override fun onNothingSelected(p:android.widget.AdapterView<*>?){} }
    }
    private fun csv(value: String): String = "\"" + (if(value.trimStart().firstOrNull() in listOf('=','+','-','@')) "'"+value else value).replace("\"","\"\"").replace("\r"," ").replace("\n"," ") + "\""
    private fun preview() {
        if (busy) return
        prepared=null
        if (!consent.isChecked) { status.text="Review and select the consent checkbox first."; return }
        val selected=source.selectedItemPosition
        val period=if(days.selectedItemPosition==0) 7L else 30L
        busy=true
        lifecycleScope.launch {
            try {
                if(HealthConnectClient.getSdkStatus(this@MainActivity)!=HealthConnectClient.SDK_AVAILABLE) error("Health Connect unavailable")
                val client=HealthConnectClient.getOrCreate(this@MainActivity)
                val granted=client.permissionController.getGrantedPermissions()
                if(!granted.containsAll(core)) error("Permission revoked")
                val end=Instant.now(); val start=end.minus(period,ChronoUnit.DAYS)
                val origin=when(selected){0->"com.sec.android.app.shealth";1->"com.google.android.apps.fitness";else->null}
                val origins=origin?.let{setOf(DataOrigin(it))}?:emptySet()
                val rows=mutableListOf<ExerciseSessionRecord>(); var token:String?=null; var pages=0; val seen=mutableSetOf<String>()
                do {
                    val page=client.readRecords(ReadRecordsRequest(ExerciseSessionRecord::class, TimeRangeFilter.between(start,end), dataOriginFilter=origins, pageSize=200, pageToken=token))
                    rows.addAll(page.records); token=page.pageToken; pages++
                    if(pages>20 || (token!=null && !seen.add(token!!))) error("Incomplete activity pagination")
                    if(rows.size>2000) error("Choose a shorter period")
                } while(token!=null)
                val output=StringBuilder("id,title,type,start_time,end_time,duration_seconds,distance_m,steps,calories_kcal,origin\n")
                val availableMetrics=buildSet<AggregateMetric<*>> { if(granted.contains(HealthPermission.getReadPermission(DistanceRecord::class)))add(DistanceRecord.DISTANCE_TOTAL);if(granted.contains(HealthPermission.getReadPermission(StepsRecord::class)))add(StepsRecord.COUNT_TOTAL) }
                for(row in rows) {
                    val aggregate=if(availableMetrics.isEmpty())null else client.aggregate(AggregateRequest(availableMetrics,TimeRangeFilter.between(row.startTime,row.endTime),dataOriginFilter=setOf(row.metadata.dataOrigin)))
                    val distance=aggregate?.get(DistanceRecord.DISTANCE_TOTAL)?.inMeters?.toString()?:""
                    val steps=aggregate?.get(StepsRecord.COUNT_TOTAL)?.toString()?:""
                    // Health Connect does not provide a guaranteed active-duration total here. Leave it absent.
                    output.append(listOf(row.metadata.id,row.title?:"Workout", "Exercise type ${row.exerciseType}",row.startTime.toString(),row.endTime.toString(),"",distance,steps,"",row.metadata.dataOrigin.packageName).joinToString(","){csv(it)}).append('\n')
                }
                if(source.selectedItemPosition!=selected||period!=(if(days.selectedItemPosition==0)7L else 30L)||!consent.isChecked) error("Selection changed")
                prepared=if(rows.isEmpty())null else output.toString()
                status.text="Preview: ${rows.size} workouts from ${origin?:"all permitted apps"}, last $period days. Optional missing metrics are blank. Active duration is unavailable; elapsed time is shown in Writer. Overlapping recordings are flagged there. ${if(rows.isEmpty())"No export was prepared. Check source sharing and history permissions." else "Review this source/period, then save the CSV."}"
            } catch (_: Exception) { prepared=null;status.text="The read did not complete. Check Health Connect, permissions, source sharing and the recent period; then explicitly preview again. No partial file was saved." }
            finally { busy=false }
        }
    }
}
