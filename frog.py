import sys
import subprocess
import json
import re
import urllib2

# ty sasha for regex parsing, payload, posting
EXTRACT_FEATURE_FLAG = re.compile("""isFeatureFlagEnabled\s*\(\s*([\w\d_\.]+)\s*,\s*true\s*\)""")
EXTRACT_OPTIMISTIC_FLAG = re.compile("""isOptimisticFeatureFlagEnabled\s*\(\s*([\w\d_\.]+)\s*\)""")

def expression_to_flag(expression):
    return expression.lower().split(".")[-1]

def extract_experiment_name(line):
    flag = EXTRACT_FEATURE_FLAG.search(line)
    if flag:
        return ("feature_flag", expression_to_flag(flag.group(1)))
    else:
        flag= EXTRACT_OPTIMISTIC_FLAG.search(line)
        if flag:
            return ("optimistic_feature_flag", expression_to_flag(flag.group(1)))
        else:
            return None

def make_url(hostname, port, path):
    return 'http://{hostname}:{port}/{path}'.format(
            hostname=hostname,
            port=port,
            path=path,
    )

def call_endpoint(request):
    """Call the specified endpoint"""
    try:
        print "trying: %s" % request.get_full_url()
        response = urllib2.urlopen(request)
        return response
    except urllib2.URLError as e:
        if hasattr(e, 'code'):
            if e.code == 404:
                print "404 - experiment not found: %s" % e.message
                return None
            if e.code == 406:
                #print "experiment already exists: %s" % e.message
                return None
            else:
                raise
        else:
            print "WARNING: no return code:", str(e)
            raise


def post_experiment(hostname, port, data):
    request = urllib2.Request(
            url=make_url(hostname, port, 'experiment/management/'),
            data=json.dumps(data),
            headers={
                'Content-type': 'application/json;charset=utf-8',
                'X-Auth-Params-Email': 'xp@uber.com',
                'X-Uber-Source': 'web-toolshed',
                'X-Uber-Notify': 'squelch'
            })
    request.get_method = lambda: 'POST'
    response = call_endpoint(request)
    if response and response.code!=200:
        print "response code: %s   message: %s".format(response.code, response.msg)

def post_subscribers(hostname, port, experiment_name, subscriber_emails):
    request = urllib2.Request(
            url=make_url(hostname, port, 'follow')+"/"+experiment_name,
            data=json.dumps({'subscribers':subscriber_emails}),
            headers={
                'Content-type': 'application/json;charset=utf-8',
                'X-Auth-Params-Email': 'xp@uber.com',
                'X-Uber-Source': 'web-toolshed',
                'X-Uber-Notify': 'squelch'
            })
    request.get_method = lambda: 'POST'
    response = call_endpoint(request)
    if response and response.code!=200:
        print "response code: %s   message: %s".format(response.code, response.msg)

#HALYARD_HOST= "fiat-rainier-7.dev"
HALYARD_HOST= "localhost"
HALYARD_PORT= 4465

def create_payload(flag_name, app_choice, experiment_type):
    return {
        "experiment_name": flag_name,
        "experiment_type": "experiment",
        "experiment_description": "optimistic feature flag " + flag_name,
        "enabled": "true",
        "version": 4,
        "is_feature_flag": "false",
        "experiment_tags": [

        ],
        "key_metrics": [

        ],
        "analytics_enabled": "false",
        "treatment_groups": [
            {
                "treatment_group_description": "",
                "treatment_group_value": {

                },
                "treatment_group_serial": 0,
                "treatment_group_name": "control",
                "treatment_group_proportion": 0.5
            },
            {
                "treatment_group_description": "treatment_description",
                "treatment_group_value": {

                },
                "treatment_group_serial": 0,
                "treatment_group_name": "disabled",
                "treatment_group_proportion": 0.5
            }
        ],
        "advanced_rollout": {
            "client_side_bucketing": "False",
            "platform": [
                "mobile"
            ],
            "bucket_by": "$user",
            "type": "$or",
            "children": [
                {
                    "title": "Segment1",
                    "value": 1,
                    "rollout": 1.0,
                    "bucket_by": "$user",
                    "operator": "$eq",
                    "distribution": [
                        {
                            "proportion": 0.5,
                            "treatment_group_name": "control"
                        },
                        {
                            "proportion": 0.5,
                            "treatment_group_name": "disabled"
                        }
                    ],
                    "property": "app",
                    "type": "$constraint",
                    "value": app_choice
                }
            ],
            "experiment_title": "",
            "experiment_type": experiment_type,
            "launched_app_versions": {
                "android": {
                    "rider": {
                        "minIncl": "current"
                    }
                }
            }
        }
    }

differential_id = sys.argv[1]
diff_query_cmd = "echo '{\"ids\": ["  + str(differential_id ) + "]}' | arc call-conduit --conduit-uri https://code.uberinternal.com/ differential.query"
diff_query_json = subprocess.check_output(diff_query_cmd, shell=True)
diff_query_response = json.loads(diff_query_json)['response'][0]

# Get emails of everyone who should subscribe to the experiment
subscriber_phids = [diff_query_response['authorPHID']]+ diff_query_response['reviewers'] + diff_query_response['ccs']
subscriber_emails = []
for subscriber_phid in subscriber_phids:
    user_query_cmd = "echo '{\"phids\":[\"" + str(subscriber_phid) + "\"]}'"
    user_query_cmd += " | arc call-conduit --conduit-uri https://code.uberinternal.com/ user.query"
    user_query_json = subprocess.check_output(user_query_cmd, shell=True)
    user_query_response = json.loads(user_query_json)['response'][0]
    email = user_query_response['email']
    if email != u'infra+jenkins@uber.com':
        subscriber_emails.append(email)


# Get the diff body itself, filter for feature flags
diffs = map(int,diff_query_response['diffs'])
addition_regex = re.compile("^\+")
experiment_names = {}
for diff in diffs:
    getrawdiff_cmd = "echo '{\"diffID\":"  + str(diff) + "}' | arc call-conduit --conduit-uri https://code.uberinternal.com/ differential.getrawdiff"
    getrawdiff_json = subprocess.check_output(getrawdiff_cmd, shell=True)
    getrawdiff_response = json.loads(getrawdiff_json)['response']

    for line in getrawdiff_response.splitlines():
        if addition_regex.match(line):
            result = extract_experiment_name(line) 
            if not result is None:
                flag_type, experiment_name = result
                experiment_names[experiment_name ] = flag_type

# Send experiment to halyard
for experiment_name, experiment_type in experiment_names.iteritems():
    payload = create_payload(experiment_name, "rider", experiment_type)
    post_experiment(HALYARD_HOST, HALYARD_PORT, payload)
    post_subscribers(HALYARD_HOST, HALYARD_PORT, experiment_name, subscriber_emails)   

