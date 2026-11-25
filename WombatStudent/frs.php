<?php
session_start();
if (!isset($_SESSION["id_user"])) {
    header("Location: ../index.php");
    exit;
}
require_once "../Koneksi.php";

$npm    = trim($_SESSION["id_user"]);

$periode = $_GET["periode"] ?? ($_SESSION["periode"] ?? "1");
$semester = $_GET["semester"] ?? ($_SESSION["semester"] ?? "25");

$id_sem = $semester . "-" . $periode;

//query semester
$stmt = $conn->prepare("SELECT Periode, Tahun_Akademik FROM Semester WHERE Id_Sem = ?");
$stmt->execute([$id_sem]);
$sem = $stmt->fetch(PDO::FETCH_ASSOC);
$semLabel = trim($sem['Periode'] ?? 'Ganjil') . ' ' . ($sem['Tahun_Akademik'] ?? '2025') . '/' . (($sem['Tahun_Akademik'] ?? 2025) + 1);

$msg = '';
//cek apakah request beradal dri form post dan apakah ada data selected_mk
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_mk'])) {
    $selected = array_map('trim', $_POST['selected_mk']); //ambil mata kuliah yang dicentang user
    if (!empty($selected)) { //kalau ada matkul yang dipilih
        $placeholders = implode(',', array_fill(0, count($selected), '?')); //mengguabung kode=kode matakuliah yang dipilih menjadi array of string
        $params = array_merge([trim($id_sem)], $selected); //gabungkan semester dan daftar mata kuliah
        //query untuk mengambbil id mk, nama mk, sks mk, dan jadwalnya
        $stmt2 = $conn->prepare("
            SELECT mk.Id_MK, mk.Nama AS NamaMK, mk.SKS
            FROM Matakuliah as mk
            JOIN Detail_Akademik as da ON da.Id_MK = mk.id_mk
            WHERE da.id_sem = ? AND mk.Id_MK IN ($placeholders)  AND mk.Status_Aktif = 1
        "); //arti dari ? adalah placeholder yang nantinya bisa diisi
        $stmt2->execute($params);
        $MKSelected = $stmt2->fetchAll(PDO::FETCH_ASSOC);//ambil semua jadwal matkul yang dipilih

        try {
            $conn->beginTransaction(); //muai transaction database
            $id_frs = substr($npm, -4) . $id_sem;// substring dan trim, substring dari berapa sampe berapa kalo trim langsung potong supaya jadi id frs
            $stmt2 = $conn->prepare("SELECT Id_FRS FROM FRS WHERE Id_FRS = ?"); //cek apakah frs udh ada, kalau belum insert kode frs baru
            //insert kode frs baru     
            $stmt2->execute([$id_frs]); 
            if (!$stmt2->fetch()) {
                $conn->prepare("INSERT INTO FRS (Id_FRS, NPM, Id_Sem) VALUES (?, ?, ?)")->execute([$id_frs, $npm, $id_sem]);
            }

            //hapus enroll lama apabila terjaid perubahan frs
            $conn->prepare("
                DELETE FROM Enroll 
                WHERE Id_FRS = (
                    SELECT Id_FRS 
                    FROM FRS 
                    WHERE NPM = ? AND Id_Sem = ?
                ) 
            ")->execute([$npm, $id_sem]);
            //inseert enroll baru
            $ins = $conn->prepare("
                INSERT INTO Enroll (NPM, Id_MK, Id_Sem, Id_FRS) 
                VALUES (?, ?, ?, (
                    SELECT Id_FRS 
                    FROM FRS 
                    WHERE NPM = ? AND Id_Sem = ?
                ))
            ");
            //loop untuk semua matkul yang dipilih, masukan ke tabel enroll
            foreach ($selected as $id_mk) {
                $ins->execute([$npm, $id_mk, $id_sem, $npm, $id_sem]);
            }
            $conn->commit();//simpan semua perubahan permanen
            $_SESSION['frs_result'] = ['semester' => $semLabel, 'courses' => $MKSelected];//simpan hasil ke session
            header("Location: frs_sukses.php");
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $msg = 'error: ' . $e->getMessage();
        }
    }
}
