import contextlib
import importlib.util
import io
import pathlib
import unittest
from unittest.mock import patch

path = pathlib.Path(__file__).parents[2] / "scripts" / "trunkrs-cloud-check.py"
spec = importlib.util.spec_from_file_location("cloud_check", path)
cloud = importlib.util.module_from_spec(spec)
spec.loader.exec_module(cloud)
CHECK = "11111111-1111-4111-8111-111111111111"


def completed(**changes):
    return dict({
        "check_id": CHECK, "state": "completed", "result": "ok", "mailbox_completed": True,
        "report_current": True, "report_date": "2026-09-25", "expected_delivery_date": "2026-09-25",
        "requested_at": "2026-09-26T04:17:00+00:00", "started_at": "2026-09-26T04:17:01+00:00",
        "last_checked_at": "2026-09-26T04:17:20+00:00",
        "report_received_at": "2026-09-26T04:01:00+00:00",
        "report_imported_at": "2026-09-26T04:17:10+00:00",
    }, **changes)


class CloudCheckTest(unittest.TestCase):
    def test_current_report_with_fresh_mailbox_scan_is_required(self):
        with contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertTrue(cloud.verify_report(completed(shipments=["private"]), CHECK))
        self.assertNotIn("private", output.getvalue())
        self.assertIn("2026-09-25", output.getvalue())

    def test_acceptance_running_and_old_success_are_not_completion(self):
        for state in ["queued", "running"]:
            self.assertFalse(cloud.verify_report({"check_id": CHECK, "state": state}, CHECK))
        for overrides in [
            {"check_id": "other"}, {"state": "failed"}, {"result": "network"},
            {"mailbox_completed": False}, {"report_current": False}, {"report_current": "true"},
            {"report_date": "2026-09-24"}, {"expected_delivery_date": "2026-09-24"},
            {"report_received_at": "2026-09-25T04:01:00+00:00"},
            {"last_checked_at": "2026-09-25T04:17:20+00:00"},
            {"last_checked_at": "2026-09-26T04:17:20"}, {"report_imported_at": None},
        ]:
            with self.subTest(overrides=overrides), self.assertRaises(cloud.CheckError):
                cloud.verify_report(completed(**overrides), CHECK)

    def test_polls_correlated_check_without_second_post(self):
        responses = [(202, {"check_id": CHECK}), (200, {"check_id": CHECK, "state": "running"}), (200, completed())]
        with patch.object(cloud, "identity_token", return_value="secret"), \
                patch.object(cloud, "request_json", side_effect=responses) as request, \
                patch.object(cloud.time, "sleep"), contextlib.redirect_stdout(io.StringIO()):
            cloud.run()
        self.assertEqual(3, request.call_count)
        self.assertEqual((cloud.ENDPOINT, "secret", "POST"), request.call_args_list[0].args)
        self.assertEqual((cloud.ENDPOINT + "/" + CHECK, "secret"), request.call_args_list[1].args)

    def test_empty_missing_and_stale_reports_keep_failure_with_specific_evidence(self):
        cases = [
            ({"report_status": "empty_undated", "report_date": None}, "empty report was imported"),
            ({"report_status": "missing", "report_date": None,
              "report_received_at": None, "report_imported_at": None}, "no imported report"),
            ({"report_status": "not_received_today", "report_date": None,
              "report_received_at": "2026-09-25T04:01:00+00:00"}, "not received on the requested Dutch day"),
            ({"report_status": "unexpected_delivery_date", "report_date": "2026-09-24"}, "does not confirm yesterday"),
            ({"report_status": "date_unconfirmed", "report_date": None}, "delivery date is unconfirmed"),
        ]
        for changes, message in cases:
            with self.subTest(changes=changes), contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaisesRegex(cloud.CheckError, message):
                    cloud.verify_report(completed(report_current=False, **changes), CHECK)
                self.assertIn("Mailbox completed:", output.getvalue())
                self.assertNotIn("Delivery date:", output.getvalue())

    def test_no_completion_claim_without_fresh_scan_evidence(self):
        for changes in [{"started_at": None}, {"requested_at": None}, {"last_checked_at": None},
                        {"started_at": "2026-09-26T04:17:30+00:00"}, {"result": "invalid_report"}]:
            with self.subTest(changes=changes), contextlib.redirect_stdout(io.StringIO()) as output:
                with self.assertRaises(cloud.CheckError):
                    cloud.verify_report(completed(report_status="empty_undated", report_current=False,
                                                  report_date=None, **changes), CHECK)
                self.assertEqual("", output.getvalue())

    def test_report_timestamps_and_contradictory_current_status_fail_closed(self):
        for changes in [{"report_imported_at": None}, {"report_received_at": None},
                        {"report_imported_at": "2026-09-26T04:00:00+00:00"},
                        {"report_imported_at": "2026-09-26T04:18:00+00:00"},
                        {"report_received_at": "2026-09-26T04:01:00"},
                        {"report_status": "empty_undated"}]:
            with self.subTest(changes=changes), contextlib.redirect_stdout(io.StringIO()):
                with self.assertRaises(cloud.CheckError):
                    cloud.verify_report(completed(**changes), CHECK)

    def test_dutch_day_boundaries_and_dst_use_request_day(self):
        for requested, received, day in [
            ("2026-10-04T22:17:00+00:00", "2026-10-04T22:01:00+00:00", "2026-10-04"),
            ("2026-03-29T04:17:00+00:00", "2026-03-28T23:01:00+00:00", "2026-03-28"),
            ("2026-10-25T05:17:00+00:00", "2026-10-24T22:01:00+00:00", "2026-10-24"),
        ]:
            with self.subTest(requested=requested), contextlib.redirect_stdout(io.StringIO()):
                self.assertTrue(cloud.verify_report(completed(
                    requested_at=requested, started_at=requested, last_checked_at=requested,
                    report_received_at=received, report_imported_at=requested,
                    report_date=day, expected_delivery_date=day, report_status="current"), CHECK))

    def test_failed_report_diagnostics_do_not_echo_arbitrary_response_values(self):
        with contextlib.redirect_stdout(io.StringIO()) as output:
            with self.assertRaises(cloud.CheckError) as error:
                cloud.verify_report(completed(report_current=False, report_status="private::error::injected",
                                              report_date="private-date", shipments=["private-shipment"]), CHECK)
        self.assertNotIn("private", output.getvalue() + str(error.exception))

    def test_old_server_acceptance_without_check_id_fails(self):
        with patch.object(cloud, "identity_token", return_value="secret"), \
                patch.object(cloud, "request_json", return_value=(202, {"message": "accepted"})) as request, \
                contextlib.redirect_stdout(io.StringIO()), self.assertRaises(cloud.CheckError):
            cloud.run()
        self.assertEqual(1, request.call_count)

    def test_dead_worker_times_out_without_starting_duplicate_check(self):
        with patch.object(cloud, "identity_token", return_value="secret"), \
                patch.object(cloud, "request_json", return_value=(202, {"check_id": CHECK})) as request, \
                patch.object(cloud.time, "monotonic", side_effect=[0, 421]), \
                contextlib.redirect_stdout(io.StringIO()), self.assertRaises(cloud.CheckError):
            cloud.run()
        self.assertEqual(1, request.call_count)

    def test_redirects_are_not_followed(self):
        self.assertIsNone(cloud.NoRedirect().redirect_request(None, None, 302, "", {}, "https://other.test"))


if __name__ == "__main__":
    unittest.main()
